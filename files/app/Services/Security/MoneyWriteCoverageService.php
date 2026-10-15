<?php

declare(strict_types=1);

namespace App\Services\Security;

use Symfony\Component\Finder\Finder;

/**
 * Where in this application is wallet money actually written?
 *
 * WHY THIS EXISTS
 * The finance engine is the only place a balance change is supposed to happen,
 * because that is the only place it is accompanied by a ledger row, a lock and
 * the settlement guards. A balance written anywhere else moves real money
 * without a record of it moving — which is exactly the failure the settlement
 * guard suite exists to catch after the fact.
 *
 * This is the money-side counterpart of `PasswordWriteCoverageService`: a static
 * scan that names every write site, so a new one has to be added on purpose
 * rather than by accident.
 *
 * WHAT IT REPORTS
 *  - every write to a money column under app/, in either shape:
 *      assignment   `$wallet->balance = …`
 *      array update `Wallet::…->update(['balance' => …])`
 *    classified against the finance engine, plus a named exception list with
 *    reasons;
 *  - the request classes that accept a client-supplied `balance` — a deposit or
 *    withdrawal form that lets the caller set the balance is not a bug waiting
 *    to happen, it is the bug.
 *
 * HOW THE ARRAY SHAPE IS CLASSIFIED
 * `'balance' => …` on its own says nothing: `WalletHoldService::snapshot()`
 * returns an array with a `balance` key and writes nothing. For each such line
 * the scan walks backwards to the nearest line that opens the statement and uses
 * that to decide: `->update(`, `->fill(`, `->create(`, `upsert(` or `->insert(`
 * mean a write; `return [` means a payload.
 *
 * WHAT IT DOES NOT DO
 * It reads source text. A write through a variable column name, a model
 * mutator, a raw statement, or a package is invisible to it, and it does not
 * judge whether an allowlisted writer is correct — only that the write happens
 * there.
 */
final class MoneyWriteCoverageService
{
    /**
     * @var list<string>
     */
    private const MONEY_COLUMNS = [
        'balance',
        'locked_balance',
        'total_deposited',
        'total_withdrawn',
        'total_wagered',
        'total_won',
    ];

    /**
     * The finance engine: the only writers that also produce ledger rows.
     *
     * @var list<string>
     */
    private const ALLOWED_WRITERS = [
        'app/Services/Finance/WalletService.php',
        'app/Services/Finance/WalletHoldService.php',
    ];

    /**
     * @var array<string, string>
     */
    private const KNOWN_EXCEPTIONS = [
        // Part 69: `ensureAgentWallet()` writes the money columns only inside
        // `if (! $wallet instanceof Wallet)`, i.e. while constructing a wallet
        // that does not exist yet, and only ever to '0.00'. It is
        // initialisation, not a mutation, so there is no ledger row to miss.
        // It stays listed rather than allowlisted: an allowlisted file may write
        // money, this one may not start to.
        'app/Services/Agent/AgentOnboardingService.php' => 'initialises a newly created agent wallet to zero inside ensureAgentWallet(); not a mutation of an existing balance',
    ];

    /**
     * @var list<string>
     */
    private const WRITE_OPENERS = ['->update(', '->fill(', '->create(', '::create(', 'upsert(', '->insert('];

    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array{scanned_files: int, write_sites: list<array{file: string, line: int, column: string, shape: string}>, covered: list<string>, uncovered: list<string>, known_exceptions: list<string>, client_writable_balance: list<string>}
     */
    public function report(): array
    {
        $base = $this->basePath ?? base_path();

        $scanned = 0;
        $sites = [];
        $clientWritable = [];

        foreach ($this->phpFiles($base.'/app') as $file) {
            $scanned++;
            $path = $file->getRealPath() ?: $file->getPathname();
            $relative = $this->relative($base, $path);
            $lines = file($path) ?: [];

            if (str_starts_with($relative, 'app/Models/')) {
                continue; // casts and fillable lists are not writes
            }

            foreach ($lines as $index => $line) {
                $number = $index + 1;
                $text = (string) $line;

                if ($this->isComment($text)) {
                    continue;
                }

                foreach (self::MONEY_COLUMNS as $column) {
                    if (preg_match('/->'.$column.'\s*=[^=]/', $text) === 1) {
                        $sites[] = ['file' => $relative, 'line' => $number, 'column' => $column, 'shape' => 'assignment'];

                        continue 2;
                    }

                    if (preg_match("/['\"]".$column."['\"]\s*=>/", $text) === 1
                        && $this->opensAWrite($lines, $index)) {
                        $sites[] = ['file' => $relative, 'line' => $number, 'column' => $column, 'shape' => 'array'];

                        continue 2;
                    }
                }

                // A validation rule for `balance` that is anything other than
                // `prohibited` on a money form means the client can set it.
                if (preg_match("/['\"]balance['\"]\s*=>\s*\[/", $text) === 1
                    && ! str_contains($text, "'prohibited'")
                    && ! str_contains($text, '"prohibited"')) {
                    $clientWritable[] = $relative.':'.$number;
                }
            }
        }

        $covered = [];
        $uncovered = [];
        $exceptions = [];

        foreach ($sites as $site) {
            $label = $site['file'].':'.$site['line'].' '.$site['column'];

            if (in_array($site['file'], self::ALLOWED_WRITERS, true)) {
                $covered[] = $label;
            } elseif (array_key_exists($site['file'], self::KNOWN_EXCEPTIONS)) {
                $exceptions[] = $label;
            } else {
                $uncovered[] = $label;
            }
        }

        return [
            'scanned_files' => $scanned,
            'write_sites' => $sites,
            'covered' => array_values(array_unique($covered)),
            'uncovered' => array_values(array_unique($uncovered)),
            'known_exceptions' => array_values(array_unique($exceptions)),
            'client_writable_balance' => array_values(array_unique($clientWritable)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function knownExceptions(): array
    {
        return self::KNOWN_EXCEPTIONS;
    }

    /**
     * @return list<string>
     */
    public function allowedWriters(): array
    {
        return self::ALLOWED_WRITERS;
    }

    /**
     * Walk back from an array entry to the statement that opens it and decide
     * whether that statement writes.
     *
     * @param  list<string>  $lines
     */
    private function opensAWrite(array $lines, int $index): bool
    {
        for ($back = $index; $back >= 0 && $back > $index - 15; $back--) {
            $text = (string) ($lines[$back] ?? '');

            if ($this->isComment($text)) {
                continue;
            }

            foreach (self::WRITE_OPENERS as $opener) {
                if (str_contains($text, $opener)) {
                    return true;
                }
            }

            if (preg_match('/return\s*(\[|array\()/', $text) === 1) {
                return false;
            }
        }

        return false;
    }

    private function isComment(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '#');
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        if (! is_dir($directory)) {
            return [];
        }

        $finder = new Finder;

        return $finder->files()->in($directory)->name('*.php')->sortByName();
    }

    private function relative(string $base, string $path): string
    {
        $prefix = rtrim($base, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
