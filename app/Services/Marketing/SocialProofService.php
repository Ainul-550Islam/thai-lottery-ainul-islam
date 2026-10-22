<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\MemberTestimonial;
use App\Models\User;
use App\Support\ResponsibleGamingGate;
use App\Support\Text;
use Illuminate\Support\Carbon;

/**
 * Compliance-Safe Social Proof Service (Audit §D6.6 + §D5 Open Item #3).
 *
 * WHY THIS DIFFERS FROM E-COMMERCE SOCIAL PROOF
 * ---------------------------------------------
 * 1. NEVER fabricates simulated wins or fake tickers. When zero qualifying
 *    settled payouts exist, `recent_wins` returns `[]` honestly.
 * 2. NEVER builds a "top spenders / biggest bettors" leaderboard — ranking
 *    players by wagering volume is a problem-gambling inducement hazard.
 * 3. Masks every member handle (`somchai` -> `so***ai`, UTF-8 codepoint safe)
 *    and strips all HTML from submitted testimonials.
 * 4. Rejects testimonials containing prohibited guaranteed-win / debt-recovery
 *    claims and holds every submission in `pending` until reviewed by another user.
 * 5. Returns an empty feed immediately when the viewing member is self-excluded.
 */
final class SocialProofService
{
    /**
     * Mask a username/display name without breaking multi-byte Thai/Bengali characters.
     */
    public function anonymiseHandle(string $raw): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', Text::plainFromHtml($raw)) ?? '');
        if ($clean === '') {
            return 'Member ***';
        }

        // Strip email domain immediately if an email address was passed.
        if (str_contains($clean, '@')) {
            $clean = (string) strstr($clean, '@', true);
        }

        $length = mb_strlen($clean, 'UTF-8');
        if ($length <= 2) {
            return mb_substr($clean, 0, 1, 'UTF-8').'***';
        }

        if ($length <= 4) {
            return mb_substr($clean, 0, 1, 'UTF-8').'***'.mb_substr($clean, -1, 1, 'UTF-8');
        }

        return mb_substr($clean, 0, 2, 'UTF-8').'***'.mb_substr($clean, -2, 2, 'UTF-8');
    }

    /**
     * Detect prohibited gambling-inducement claims in any supported language.
     */
    public function containsProhibitedClaim(string $text): bool
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 'UTF-8');
        if ($normalised === '') {
            return false;
        }

        /** @var array<int, string> $phrases */
        $phrases = (array) config('conversion_tooling.social_proof.prohibited_phrases', []);

        foreach ($phrases as $phrase) {
            $needle = mb_strtolower(trim((string) $phrase), 'UTF-8');
            if ($needle !== '' && mb_strpos($normalised, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Submit a member testimonial for moderation (`status = pending`).
     */
    public function submitTestimonial(
        User $author,
        string $rawQuote,
        int $rating = 5,
        string $locale = 'en',
    ): MemberTestimonial {
        if (ResponsibleGamingGate::isSelfExcluded($author)) {
            throw new \DomainException('Testimonials cannot be submitted during an active self-exclusion.');
        }

        $cleanQuote = trim(Text::plainFromHtml($rawQuote));
        $len = mb_strlen($cleanQuote, 'UTF-8');
        $minLen = (int) config('conversion_tooling.social_proof.quote_min_length', 20);
        $maxLen = (int) config('conversion_tooling.social_proof.quote_max_length', 280);

        if ($len < $minLen || $len > $maxLen) {
            throw new \InvalidArgumentException(sprintf(
                'Testimonial must be between %d and %d characters.',
                $minLen,
                $maxLen,
            ));
        }

        if ($this->containsProhibitedClaim($cleanQuote)) {
            throw new \DomainException('Testimonial contains prohibited outcome-guarantee claims.');
        }

        $clampedRating = max(1, min(5, $rating));
        $handleSource = (string) ($author->username ?? $author->first_name ?? 'Member');

        return MemberTestimonial::query()->create([
            'user_id' => (int) $author->getKey(),
            'display_name' => $this->anonymiseHandle($handleSource),
            'locale' => substr(strtolower(trim($locale)), 0, 8) ?: 'en',
            'quote' => $cleanQuote,
            'rating' => $clampedRating,
            'status' => MemberTestimonial::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'published_at' => null,
        ]);
    }

    /**
     * Approve a pending testimonial (four-eyes: author cannot approve their own quote).
     */
    public function approveTestimonial(MemberTestimonial $testimonial, User|int $reviewer): MemberTestimonial
    {
        $reviewerId = $reviewer instanceof User ? (int) $reviewer->getKey() : (int) $reviewer;

        if ($testimonial->user_id !== null && (int) $testimonial->user_id === $reviewerId) {
            throw new \DomainException('Authors cannot approve their own testimonial.');
        }

        if ($this->containsProhibitedClaim((string) $testimonial->quote)) {
            throw new \DomainException('Cannot approve a testimonial containing prohibited claims.');
        }

        $now = Carbon::now();
        $testimonial->status = MemberTestimonial::STATUS_APPROVED;
        $testimonial->reviewed_by = $reviewerId;
        $testimonial->reviewed_at = $now;
        $testimonial->published_at ??= $now;
        $testimonial->save();

        return $testimonial;
    }

    /**
     * Assemble the public social-proof feed for home / landing views.
     *
     * @return array{
     *     visible: bool,
     *     recent_wins: list<array{masked_member: string, amount: string, currency: string, settled_at: ?string}>,
     *     testimonials: list<array{display_name: string, quote: string, rating: int}>,
     *     disclosure: string
     * }
     */
    public function publicFeed(?User $viewer = null, ?string $locale = null): array
    {
        $resolvedLocale = strtolower(trim((string) ($locale ?? app()->getLocale())));
        $disclosure = $this->disclosureFor($resolvedLocale);

        $empty = [
            'visible' => false,
            'recent_wins' => [],
            'testimonials' => [],
            'disclosure' => $disclosure,
        ];

        if (! (bool) config('conversion_tooling.social_proof.enabled', true)) {
            return $empty;
        }

        if ($viewer instanceof User && ResponsibleGamingGate::isSelfExcluded($viewer)) {
            return $empty;
        }

        $winsLimit = max(0, (int) config('conversion_tooling.social_proof.recent_wins_limit', 6));
        $minPayout = bcadd((string) config('conversion_tooling.social_proof.min_display_payout', '100.00'), '0', 2);

        $recentWins = [];
        if ($winsLimit > 0) {
            $payoutRows = FinancialTransaction::query()
                ->where('type', TransactionType::Payout->value)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->limit($winsLimit * 2)
                ->get();

            foreach ($payoutRows as $row) {
                $amt = bcadd((string) ($row->amount ?? '0.00'), '0', 2);
                if (bccomp($amt, $minPayout, 2) < 0) {
                    continue;
                }

                $owner = $row->user_id ? User::query()->find((int) $row->user_id) : null;
                $handle = $owner instanceof User
                    ? $this->anonymiseHandle((string) ($owner->username ?? 'Member'))
                    : 'Member ***';

                $currencyStr = $row->currency instanceof \BackedEnum
                    ? (string) $row->currency->value
                    : (string) ($row->currency ?? 'THB');

                $recentWins[] = [
                    'masked_member' => $handle,
                    'amount' => $amt,
                    'currency' => $currencyStr,
                    'settled_at' => $row->created_at?->toIso8601String(),
                ];

                if (count($recentWins) >= $winsLimit) {
                    break;
                }
            }
        }

        $testimonialsLimit = max(0, (int) config('conversion_tooling.social_proof.testimonials_limit', 3));
        $testimonials = [];

        if ($testimonialsLimit > 0) {
            $approvedRows = MemberTestimonial::query()
                ->where('status', MemberTestimonial::STATUS_APPROVED)
                ->where('locale', $resolvedLocale)
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->limit($testimonialsLimit)
                ->get();

            foreach ($approvedRows as $item) {
                $testimonials[] = [
                    'display_name' => (string) $item->display_name,
                    'quote' => (string) $item->quote,
                    'rating' => max(1, min(5, (int) $item->rating)),
                ];
            }
        }

        return [
            'visible' => $recentWins !== [] || $testimonials !== [],
            'recent_wins' => $recentWins,
            'testimonials' => $testimonials,
            'disclosure' => $disclosure,
        ];
    }

    public function disclosureFor(string $locale): string
    {
        /** @var array<string, string> $map */
        $map = (array) config('conversion_tooling.social_proof.disclosure', []);

        return (string) ($map[$locale] ?? $map['en'] ?? 'Play responsibly. 18+');
    }
}
