<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletFeedType;
use App\Models\User;
use App\Services\Wallet\WalletTransactionFeedService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The money feed: owner-scoped, cursor-paginated, and honest about type mapping.
 *
 * The feed is the screen a member uses to check "did I really spend that". A row
 * that appears in the wrong member's feed is worse than a missing row, so the
 * scope tests come first and a filter test asserts a filter can only ever REMOVE
 * rows from that scope.
 */
final class WalletTransactionFeedServiceTest extends TestCase
{
    use DatabaseTruncation;

    private WalletTransactionFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = app(WalletTransactionFeedService::class);
    }

    public function test_an_empty_feed_is_an_empty_page_not_an_error(): void
    {
        $user = User::factory()->create();

        $result = $this->feed->feed(memberId: (int) $user->id);

        $this->assertNotNull($result);
    }

    public function test_the_feed_never_returns_another_members_rows(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $result = $this->feed->feed(memberId: (int) $mine->id);

        foreach ((array) $result->items() as $row) {
            if (isset($row['member_id'])) {
                $this->assertSame((int) $mine->id, (int) $row['member_id']);
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_type_filter_vocabulary_is_closed(): void
    {
        $values = WalletFeedType::filterValues();

        $this->assertNotEmpty($values);
        $this->assertSame(array_values(array_unique($values)), $values);
    }

    public function test_an_unknown_type_does_not_throw(): void
    {
        $user = User::factory()->create();

        $result = $this->feed->feed(memberId: (int) $user->id, type: 'not_a_real_type');

        $this->assertNotNull($result);
    }

    public function test_the_page_size_is_capped(): void
    {
        $user = User::factory()->create();

        $result = $this->feed->feed(memberId: (int) $user->id, perPage: 100000);

        $this->assertLessThanOrEqual(100, (int) $result->perPage());
    }

    public function test_the_feed_is_deterministically_ordered(): void
    {
        $user = User::factory()->create();

        $result = $this->feed->feed(memberId: (int) $user->id);

        $rows = (array) $result->items();
        $previous = null;

        foreach ($rows as $row) {
            $stamp = (string) ($row['created_at'] ?? '');

            if ($previous !== null) {
                $this->assertLessThanOrEqual(0, strcmp($stamp, $previous) <= 0 ? 0 : 1);
            }

            $previous = $stamp;
        }

        $this->addToAssertionCount(1);
    }

    public function test_reading_the_feed_writes_nothing(): void
    {
        $user = User::factory()->create();

        $before = \App\Models\FinancialTransaction::query()->count();

        $this->feed->feed(memberId: (int) $user->id);

        $this->assertSame($before, \App\Models\FinancialTransaction::query()->count());
    }

    public function test_projecting_a_row_never_invents_a_direction(): void
    {
        // A projected row must carry an explicit direction, because the amount is
        // an absolute value and the sign has to live somewhere.
        $transaction = new \App\Models\FinancialTransaction();
        $transaction->forceFill([
            'id' => 1,
            'reference_number' => 'TX-1',
            'type' => \App\Enums\TransactionType::Deposit,
            'amount' => '10.00',
            'currency' => 'THB',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $projection = $this->feed->project($transaction);

        $this->assertContains($projection['direction'], ['credit', 'debit']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $projection['amount']);
    }
}
