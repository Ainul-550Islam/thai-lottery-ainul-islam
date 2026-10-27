<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use App\Contracts\Lottery\ProvidesResultProvenance;
use App\Enums\GloSourceState;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Source classification and public provenance projection, shared by every
 * result product lane.
 *
 * WHY GloSourceState AND NOT A NEW ENUM PER LANE
 * ---------------------------------------------------------------------------
 * A deliberate, reported decision carried forward from PROMPT 5.
 * App\Enums\GloSourceState already carries this platform's provenance
 * vocabulary - OFFICIAL_SOURCE_VERIFIED, OFFICIAL_SOURCE_CONFIGURED,
 * INTERNAL_RECONCILED, FIXTURE_ONLY, NOT_CONFIGURED, UNAVAILABLE - and those
 * words mean exactly the same thing about a Weekly payload as about a
 * National one or a GLO one: they describe how much is known about where data
 * came from, not which product it belongs to.
 *
 * The Glo prefix is historical naming, not a product coupling. Using the enum
 * does NOT map any lane onto GLO: nothing in this class reads a glo_* table,
 * calls a GLO service, or touches a GLO price or prize calculator.
 *
 * WHY THIS CLASS WAS EXTRACTED IN PROMPT 6
 * ---------------------------------------------------------------------------
 * PROMPT 5 implemented this reasoning inside NationalLotterySourceService.
 * The Weekly lane needs identical rules. Two copies would be two places where
 * "may a fixture be called official" is decided, and the first drift between
 * them would put a production badge on development data. One class, two
 * config prefixes.
 *
 * THE RULE THIS CLASS ENFORCES
 * ---------------------------------------------------------------------------
 * A fixture NEVER becomes official. There is no branch below that upgrades a
 * source state, and resolve() reads the stored value rather than recomputing
 * it, so a row written FIXTURE_ONLY renders FIXTURE_ONLY forever. When a
 * configured official provider fails, the lane reports UNAVAILABLE - it does
 * not fall through to a fixture wearing a production badge.
 *
 * CREDENTIALS NEVER APPEAR IN OUTPUT
 * ---------------------------------------------------------------------------
 * A configured endpoint may contain a token. Nothing here returns the
 * endpoint; endpointHost() reduces it to a HOST, which answers "which system
 * said this" and carries nothing to steal. No method on this class returns a
 * token, and none accepts one.
 */
abstract class AbstractLotterySourceService
{
    /** The label a human-entered result claims when nothing else is configured. */
    public const OPERATOR_PROVIDER_LABEL = 'internal';

    /**
     * Every provider label {@see stateForProvider()} can classify.
     *
     * @var list<string>
     */
    public const CLASSIFIABLE_PROVIDERS = ['official', 'internal', 'operator', 'replay', 'fixture'];

    /**
     * Highest trust first. Used to break ties between two publishable
     * versions for the same draw.
     *
     * @var array<string, int>
     */
    protected const TRUST_RANK = [
        'OFFICIAL_SOURCE_VERIFIED' => 40,
        'INTERNAL_RECONCILED' => 30,
        'OFFICIAL_SOURCE_CONFIGURED' => 20,
        'FIXTURE_ONLY' => 10,
        'NOT_CONFIGURED' => 0,
        'UNAVAILABLE' => 0,
    ];

    public function __construct(
        protected readonly ConfigRepository $config,
    ) {}

    /**
     * The product lane's config file name, e.g. 'weekly_lottery'.
     */
    abstract protected function configPrefix(): string;

    protected function configKey(string $key): string
    {
        return $this->configPrefix().'.'.$key;
    }

    /**
     * The provider lanes, in the order they are asked.
     *
     * @return list<string>
     */
    public function priority(): array
    {
        $priority = $this->config->get($this->configKey('sources.priority'));

        if (! is_array($priority)) {
            return ['official', 'internal', 'fixture'];
        }

        $out = [];

        foreach ($priority as $provider) {
            if (is_string($provider) && $provider !== '') {
                $out[] = $provider;
            }
        }

        return $out === [] ? ['official', 'internal', 'fixture'] : $out;
    }

    /**
     * Is the official provider actually configured?
     *
     * "Configured" means an endpoint string exists. It does NOT mean
     * verified: a configured endpoint that has not yet returned a validated
     * payload is OFFICIAL_SOURCE_CONFIGURED, one rung below
     * OFFICIAL_SOURCE_VERIFIED.
     */
    public function officialConfigured(): bool
    {
        $endpoint = $this->config->get($this->configKey('sources.official.endpoint'));

        return is_string($endpoint) && trim($endpoint) !== '';
    }

    public function fixturesEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) $this->config->get($this->configKey('sources.fixture.enabled'), false);
    }

    /**
     * May a fixture stand in when a higher lane fails? Configured false, and
     * this method is the only reader, so the answer is one line to audit.
     */
    public function mayFallThroughToFixture(): bool
    {
        return (bool) $this->config->get($this->configKey('sources.fall_through_to_fixture'), false);
    }

    /**
     * The provider label a manual operator entry claims in this lane.
     *
     * WHY THIS LIVES ON THE SHARED SOURCE SERVICE
     * -----------------------------------------------------------------------
     * `stateForProvider('internal', true)` already maps to
     * INTERNAL_RECONCILED for every lane, so the label must be one this class
     * already understands. Reading it here - once, from the lane's own config
     * prefix - means the four lanes cannot disagree about what a human-entered
     * result is called, and cannot be pointed at a label the rest of this class
     * would classify as UNAVAILABLE.
     *
     * The label is validated against the same closed set that
     * {@see stateForProvider()} accepts. A misconfigured label therefore falls
     * back to 'internal' rather than to something the lane cannot classify,
     * because an unclassifiable label would publish as UNAVAILABLE and confuse
     * an operator who had just seen their entry accepted.
     */
    public function operatorProviderLabel(): string
    {
        $configured = $this->config->get($this->configKey('sources.operator.provider_label'));

        if (! is_string($configured)) {
            return self::OPERATOR_PROVIDER_LABEL;
        }

        $candidate = strtolower(trim($configured));

        return in_array($candidate, self::CLASSIFIABLE_PROVIDERS, true)
            ? $candidate
            : self::OPERATOR_PROVIDER_LABEL;
    }

    /**
     * Whether manual entry is open in this lane.
     *
     * Defaults to TRUE. A lane with no manual path is a lane where the day the
     * feed is late is the day someone edits the database by hand, and that path
     * has no second signature. An operator can still close it, and the reason to
     * do so is regulatory (a lane that is not permitted to publish without the
     * official feed), never convenience — so the flag is named `enabled` and
     * defaults to the safe-for-integrity value.
     */
    public function operatorEntryEnabled(): bool
    {
        return (bool) $this->config->get($this->configKey('sources.operator.enabled'), true);
    }

    /**
     * Whether a manual entry must carry a second signature before publication.
     *
     * Always true in effect: `approveVersion()` on the import engine refuses an
     * approval by the importer regardless of this value, because four eyes that
     * can be switched off with a flag is one pair of eyes. The getter exists so
     * an admin page can DISPLAY the guarantee, and so a deployment that
     * accidentally sets it false is visible in review rather than silently
     * ignored.
     */
    public function operatorRequiresSecondPerson(): bool
    {
        return (bool) $this->config->get($this->configKey('sources.operator.require_second_person'), true);
    }

    /**
     * The source state a given provider is ENTITLED to claim.
     *
     * An import cannot pass in a state of its choosing; it names its provider
     * and this method decides what that provider may be called. That is what
     * makes "fixture data labelled official" unreachable rather than merely
     * discouraged.
     *
     * 'replay' is a re-read of a payload this platform already holds. It can
     * never be more trustworthy than an internal reconciliation, because no
     * new external evidence arrived.
     */
    public function stateForProvider(string $provider, bool $payloadValidated): GloSourceState
    {
        return match ($provider) {
            'official' => ! $this->officialConfigured()
                ? GloSourceState::NotConfigured
                : ($payloadValidated
                    ? GloSourceState::OfficialSourceVerified
                    : GloSourceState::OfficialSourceConfigured),
            'internal', 'operator', 'replay' => $payloadValidated
                ? GloSourceState::InternalReconciled
                : GloSourceState::Unavailable,
            'fixture' => $this->fixturesEnabled()
                ? GloSourceState::FixtureOnly
                : GloSourceState::NotConfigured,
            default => GloSourceState::Unavailable,
        };
    }

    /**
     * Read a stored state back, without ever upgrading it.
     */
    public function resolve(?string $storedState): GloSourceState
    {
        if (! is_string($storedState) || $storedState === '') {
            return GloSourceState::Unavailable;
        }

        return GloSourceState::tryFrom($storedState) ?? GloSourceState::Unavailable;
    }

    /**
     * May a version in this source state be published at all?
     */
    public function isPublishableState(GloSourceState $state): bool
    {
        $allowed = $this->config->get($this->configKey('publication.publishable_source_states'));

        if (! is_array($allowed)) {
            return false;
        }

        return in_array($state->value, $allowed, true);
    }

    /**
     * Trust ranking used to choose between two publishable versions.
     */
    public function trustRank(GloSourceState $state): int
    {
        return static::TRUST_RANK[$state->value] ?? 0;
    }

    /**
     * Reduce a configured endpoint to a bare host.
     *
     * A full URL can carry a token in its query string. The host cannot, so
     * the host is what gets stored and what gets shown.
     */
    public function endpointHost(?string $endpoint): ?string
    {
        if (! is_string($endpoint) || trim($endpoint) === '') {
            return null;
        }

        $host = parse_url(trim($endpoint), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The configured official endpoint, read only so that its HOST can be
     * derived. The value itself is never returned to a caller.
     */
    public function officialEndpointHost(): ?string
    {
        $endpoint = $this->config->get($this->configKey('sources.official.endpoint'));

        return $this->endpointHost(is_string($endpoint) ? $endpoint : null);
    }

    /**
     * Public-safe provenance block for one version.
     *
     * Whitelisted, not filtered: the output is built key by key from the
     * interface, so a column added to a versions table tomorrow cannot appear
     * on a public page by accident. The state is additionally clamped to the
     * public vocabulary, so an internal state introduced later is downgraded
     * to UNAVAILABLE rather than leaked.
     *
     * @return array{
     *     available: bool,
     *     source_state: string,
     *     is_official: bool,
     *     is_fixture: bool,
     *     provider: string|null,
     *     source_identifier: string|null,
     *     source_host: string|null,
     *     payload_fingerprint: string|null,
     *     normalized_fingerprint: string|null,
     *     parser_version: string|null,
     *     retrieved_at: string|null,
     *     imported_at: string|null,
     *     result_version: int|null,
     *     supersedes_version: int|null
     * }
     */
    public function publicProvenance(?ProvidesResultProvenance $version): array
    {
        if (! $version instanceof ProvidesResultProvenance) {
            return [
                'available' => false,
                'source_state' => GloSourceState::Unavailable->value,
                'is_official' => false,
                'is_fixture' => false,
                'provider' => null,
                'source_identifier' => null,
                'source_host' => null,
                'payload_fingerprint' => null,
                'normalized_fingerprint' => null,
                'parser_version' => null,
                'retrieved_at' => null,
                'imported_at' => null,
                'result_version' => null,
                'supersedes_version' => null,
            ];
        }

        $state = $this->publicState($this->resolve($version->provenanceSourceState()));

        return [
            'available' => true,
            'source_state' => $state->value,
            'is_official' => $state === GloSourceState::OfficialSourceVerified,
            'is_fixture' => $state === GloSourceState::FixtureOnly,
            'provider' => $version->provenanceProvider(),
            'source_identifier' => $version->provenanceSourceIdentifier(),
            'source_host' => $version->provenanceSourceHost(),
            'payload_fingerprint' => $version->provenancePayloadFingerprint(),
            'normalized_fingerprint' => $version->provenanceNormalizedFingerprint(),
            'parser_version' => $version->provenanceParserVersion(),
            'retrieved_at' => $version->provenanceRetrievedAtIso(),
            'imported_at' => $version->provenanceImportedAtIso(),
            'result_version' => $version->provenanceVersionNumber(),
            'supersedes_version' => $version->provenanceSupersedesVersionNumber(),
        ];
    }

    /**
     * Clamp a state to the closed public vocabulary.
     */
    public function publicState(GloSourceState $state): GloSourceState
    {
        $allowed = $this->config->get($this->configKey('public_source_states'));

        if (is_array($allowed) && in_array($state->value, $allowed, true)) {
            return $state;
        }

        // OFFICIAL_SOURCE_CONFIGURED is intentionally NOT public: telling a
        // visitor "we have an endpoint but it has not verified this" invites
        // them to read the numbers as official-in-progress. Unverified is
        // unavailable, publicly.
        return GloSourceState::Unavailable;
    }

    /**
     * Translation key for the badge a template renders.
     *
     * Templates receive a KEY, never an English sentence, so the badge is
     * translatable and cannot be a hard-coded claim in one language.
     */
    abstract public function badgeKey(GloSourceState $state): string;
}
