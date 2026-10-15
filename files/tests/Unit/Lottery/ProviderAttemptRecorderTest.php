<?php

declare(strict_types=1);

namespace Tests\Unit\Lottery;

use App\Services\Lottery\ProviderAttemptRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Part 69/70 — the attempt log the result ladder was written against.
 *
 * `GloResultProviderChain` calls `skipped()`, `answered()` and `failed()` as it
 * walks its rungs and takes the recorder as a required constructor dependency,
 * but the class was never committed. The container therefore failed with
 * "Target class [App\Services\Lottery\ProviderAttemptRecorder] does not exist",
 * which turned all of `GloResultFallbackTest` into errors: 17 tests that never
 * reached a single assertion.
 *
 * These tests pin the contract the ladder and its suite depend on:
 *   * the entry shape, exactly — `GloResultFallbackTest` compares whole arrays
 *     with assertSame, so an extra key or a different order breaks it;
 *   * `answered` is recorded as outcome `imported`, because that is what the
 *     caller does with the payload;
 *   * the chain's machine codes for a skip (`circuit_open`, `not_configured`)
 *     read as sentences, and the circuit one must contain the words
 *     "circuit open" — `GloResultFallbackTest` asserts exactly that string;
 *   * order is the walk order, and `reset()` clears it, because the chain is
 *     reused and one draw's attempts must not be reported as the next one's.
 */
final class ProviderAttemptRecorderTest extends TestCase
{
    #[Test]
    public function an_answered_rung_is_recorded_as_imported_with_no_reason(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->answered('fixture', 'GLO-2026-09-16', ['status' => 'imported', 'provider' => 'fixture']);

        // The exact array GloResultFallbackTest compares with assertSame.
        $this->assertSame(
            [['source' => 'fixture', 'outcome' => 'imported', 'reason' => null]],
            $recorder->attempts(),
        );
    }

    #[Test]
    public function a_circuit_skip_reads_as_a_sentence_containing_the_words_circuit_open(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->skipped('official', 'DRAW-1', 'circuit_open');

        $attempt = $recorder->attempts()[0];

        $this->assertSame('official', $attempt['source']);
        $this->assertSame('skipped', $attempt['outcome']);
        $this->assertStringContainsString('circuit open', (string) $attempt['reason']);
    }

    #[Test]
    public function an_unconfigured_skip_is_a_deployment_fact_not_a_failure(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->skipped('official', 'DRAW-1', 'not_configured');

        $attempt = $recorder->attempts()[0];

        $this->assertSame('skipped', $attempt['outcome']);
        $this->assertStringContainsString('not configured', (string) $attempt['reason']);
    }

    #[Test]
    public function an_unrecognised_skip_code_is_passed_through_verbatim(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->skipped('secondary', 'DRAW-1', 'some_new_reason');

        $this->assertSame('some_new_reason', $recorder->attempts()[0]['reason']);
    }

    #[Test]
    public function a_failed_rung_keeps_the_reason_the_source_gave(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->failed('official', 'DRAW-1', 'connection timed out');
        $recorder->failed('fixture', 'DRAW-1', '');

        $this->assertSame('connection timed out', $recorder->attempts()[0]['reason']);
        $this->assertSame('failed without a reason', $recorder->attempts()[1]['reason']);
        $this->assertSame('failed', $recorder->attempts()[1]['outcome']);
    }

    #[Test]
    public function the_log_keeps_walk_order_and_can_be_read_backwards(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->failed('official', 'DRAW-1', 'connection timed out');
        $recorder->answered('fixture', 'DRAW-1', ['status' => 'imported']);

        $this->assertSame(2, $recorder->count());
        $this->assertSame(
            ['official', 'fixture'],
            array_column($recorder->attempts(), 'source'),
        );
        $this->assertSame('fixture', $recorder->last()['source']);
    }

    #[Test]
    public function reset_clears_the_walk_so_a_reused_chain_does_not_report_the_previous_draw(): void
    {
        $recorder = new ProviderAttemptRecorder;

        $recorder->failed('official', 'DRAW-1', 'connection timed out');
        $this->assertSame(1, $recorder->count());

        $recorder->reset();

        $this->assertSame([], $recorder->attempts());
        $this->assertSame(0, $recorder->count());
        $this->assertNull($recorder->last());
    }
}
