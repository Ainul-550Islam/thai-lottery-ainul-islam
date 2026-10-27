<?php

declare(strict_types=1);

namespace Tests\Feature\Results;

use App\Services\Lottery\PrizeCheckService;
use Tests\TestCase;

/**
 * The prize-check engine, unit by unit.
 *
 * ============================================================================
 * WHY THIS SUITE EXISTS SEPARATELY FROM THE ENDPOINT SUITE
 * ============================================================================
 * PrizeCheckEndpointTest proves the HTTP contract: verbs, status codes, the shape
 * of the envelope. This one proves the decisions underneath it, because a
 * correct envelope carrying a wrong verdict is the worse failure — it is a
 * confident wrong answer about money.
 *
 * The engine has four honest answers and one dishonest temptation:
 *
 *   CHECKED         a published, non-fixture result was read and compared
 *   NOT_CONFIGURED  nothing publishable exists yet
 *   UNAVAILABLE     a result exists but its provenance may not be presented
 *   INVALID_TICKET  the input is not a six-digit ticket
 *
 * The temptation is to answer NO_PRIZE when there is nothing to compare against,
 * because it lets the page render. Every test below that mentions an empty state
 * exists to keep that answer out.
 */
final class PrizeCheckServiceTest extends TestCase
{
    private function service(): PrizeCheckService
    {
        return $this->app->make(PrizeCheckService::class);
    }

    public function test_a_ticket_number_is_exactly_six_digits_or_it_is_invalid(): void
    {
        $service = $this->service();

        $this->assertSame('123456', $service->normaliseTicket('123456'));
        $this->assertSame('000123', $service->normaliseTicket(' 000123 '), 'surrounding space is trimmed');
        $this->assertSame('000007', $service->normaliseTicket('000007'), 'leading zeros are preserved');

        foreach (['12345', '1234567', '', '  ', 'ABCDEF', '12 34 56', '12345a', '-12345', '1.2345'] as $bad) {
            $this->assertNull($service->normaliseTicket($bad), 'accepted an invalid ticket: '.var_export($bad, true));
        }
    }

    public function test_an_invalid_ticket_is_refused_before_any_draw_is_touched(): void
    {
        $result = $this->service()->checkLatest('12345');

        $this->assertSame(PrizeCheckService::STATE_INVALID, $result['state']);
        $this->assertSame('invalid_ticket_number', $result['reason']);
        // The envelope keeps every key so a client can destructure it without
        // branching; what must be absent is a verdict VALUE.
        $this->assertNull($result['verdict'], 'an invalid ticket has no verdict');
        $this->assertNull($result['tier']);
        $this->assertNull($result['draw']);
    }

    public function test_with_no_published_draw_the_answer_is_not_configured_and_never_no_prize(): void
    {
        $result = $this->service()->checkLatest('123456');

        $this->assertSame(PrizeCheckService::STATE_NOT_CONFIGURED, $result['state']);
        $this->assertNotSame(
            PrizeCheckService::VERDICT_NO_PRIZE,
            $result['verdict'],
            '"nobody published a draw" must never be reported as "you did not win"',
        );
        $this->assertNull($result['verdict']);
        $this->assertSame('no_published_draw', $result['reason']);
    }

    public function test_the_tier_catalogue_is_money_as_decimal_strings(): void
    {
        $catalogue = $this->service()->tierCatalogue();

        $this->assertNotSame([], $catalogue, 'the catalogue must come from configuration, not from this test');

        foreach ($catalogue as $tier => $definition) {
            $this->assertIsString($tier);
            $this->assertArrayHasKey('amount', $definition, $tier.' must declare an amount');
            $this->assertIsString($definition['amount'], $tier.' amount must be a decimal string, not a float');
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $definition['amount'], $tier.' amount must have two decimal places');
            $this->assertArrayHasKey('digits', $definition);
            $this->assertGreaterThan(0, (int) $definition['digits']);
        }
    }

    public function test_a_tier_amount_is_never_truncated_to_an_integer(): void
    {
        foreach ($this->service()->tierCatalogue() as $tier => $definition) {
            $amount = (string) $definition['amount'];

            $this->assertNotSame(
                (string) (int) $amount,
                $amount,
                $tier.' lost its satang: a rounded prize figure is a different prize',
            );
        }
    }

    public function test_latest_published_draw_either_returns_a_draw_or_null(): void
    {
        $draw = $this->service()->latestPublishedDraw();

        // Either answer is legitimate depending on the database; what is not
        // legitimate is an exception or a half-built row.
        if ($draw !== null) {
            $this->assertNotSame('', (string) $draw->draw_number);
        } else {
            $this->assertNull($draw);
        }
    }

    public function test_the_state_vocabulary_is_closed(): void
    {
        // These four strings are the contract between the engine, the API
        // envelope, the JS and lang/{en,th}/frontend_ops.php. Adding a fifth
        // without a translation leaves a member looking at a raw token.
        $this->assertSame('CHECKED', PrizeCheckService::STATE_CHECKED);
        $this->assertSame('NOT_CONFIGURED', PrizeCheckService::STATE_NOT_CONFIGURED);
        $this->assertSame('UNAVAILABLE', PrizeCheckService::STATE_UNAVAILABLE);
        $this->assertSame('INVALID_TICKET', PrizeCheckService::STATE_INVALID);
    }

    public function test_the_verdict_vocabulary_is_two_words(): void
    {
        $this->assertSame('WINNING', PrizeCheckService::VERDICT_WINNING);
        $this->assertSame('NO_PRIZE', PrizeCheckService::VERDICT_NO_PRIZE);
    }

    public function test_every_state_has_a_translated_label_in_both_locales(): void
    {
        $states = [
            PrizeCheckService::STATE_CHECKED,
            PrizeCheckService::STATE_NOT_CONFIGURED,
            PrizeCheckService::STATE_UNAVAILABLE,
            PrizeCheckService::STATE_INVALID,
        ];

        // The catalogue does not key on the raw state token; each state has its
        // own sentence because "NOT_CONFIGURED" for a missing draw and for an
        // unavailable source read differently to a member. This map is the
        // contract, and it is asserted in both locales.
        $labelKeys = [
            PrizeCheckService::STATE_CHECKED => 'checked',
            PrizeCheckService::STATE_NOT_CONFIGURED => 'not_published',
            PrizeCheckService::STATE_UNAVAILABLE => 'provenance_unavailable',
            PrizeCheckService::STATE_INVALID => 'invalid_ticket',
        ];

        foreach (['en', 'th'] as $locale) {
            $lines = require lang_path($locale.'/frontend_ops.php');

            $this->assertIsArray($lines, $locale.'/frontend_ops.php must return an array');

            foreach ($states as $state) {
                $key = $labelKeys[$state];

                $this->assertArrayHasKey('results', $lines, $locale.' must carry a results section');
                $this->assertArrayHasKey($key, $lines['results'], $locale.' is missing the label for state '.$state);
                $this->assertNotSame('', trim((string) $lines['results'][$key]), $locale.' label for '.$state.' is empty');
            }
        }
    }
}
