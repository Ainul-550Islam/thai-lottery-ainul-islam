<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Exceptions\SettlementPreviewException;
use App\Models\SettlementPreview;
use App\Models\SettlementPreviewBet;
use App\Services\Draw\ChunkedSettlementPreviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Structural negative controls accompany behavioral whole-row snapshots. */
final class ChunkedSettlementPreviewSafetyTest extends TestCase
{
    private const COMPONENTS = [
        'app/Services/Draw/ChunkedSettlementPreviewService.php',
        'app/Models/SettlementPreview.php',
        'app/Models/SettlementPreviewBet.php',
        'app/Exceptions/SettlementPreviewException.php',
        'app/Console/Commands/Lottery/PreviewSettlementCommand.php',
        'database/migrations/2026_10_14_000100_create_settlement_preview_tables.php',
    ];

    public function test_component_has_no_money_provider_or_queue_dependency(): void
    {
        foreach (self::COMPONENTS as $path) {
            $this->assertSame([], $this->dangerousSymbols($this->source($path)), $path);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:->|::)\s*(?:credit|debit|pay|disburse|dispatch|batch|settle|markSettled|transitionTo)\s*\(/i',
                $this->codeOnly($this->source($path)),
                $path,
            );
        }
    }

    public function test_the_dependency_detector_rejects_code_but_not_explanatory_comments(): void
    {
        $dangerous = '<?php use App\\Services\\Finance\\WalletService; WalletService::credit();';
        $this->assertSame(['WalletService'], $this->dangerousSymbols($dangerous));
        $this->assertSame(['Http'], $this->dangerousSymbols('<?php Http::post("provider");'));
        $this->assertSame([], $this->dangerousSymbols('<?php /* WalletService Http Bus Payout */ $x = 1;'));
    }

    public function test_no_component_introduces_float_arithmetic(): void
    {
        foreach (self::COMPONENTS as $path) {
            $unsafe = array_filter(token_get_all($this->source($path)), fn ($token) => is_array($token)
                && in_array($token[0], [T_DOUBLE_CAST, T_DNUMBER], true));
            $this->assertSame([], array_values($unsafe), $path);
            $this->assertDoesNotMatchRegularExpression('/\b(?:floatval|intval|round|number_format|ceil|floor)\s*\(/', $this->codeOnly($this->source($path)), $path);
        }

        $negative = token_get_all('<?php $x = (float) 1.2;');
        $this->assertNotEmpty(array_filter($negative, fn ($token) => is_array($token) && $token[0] === T_DOUBLE_CAST));
        $this->assertNotEmpty(array_filter($negative, fn ($token) => is_array($token) && $token[0] === T_DNUMBER));
    }

    public function test_public_inputs_cannot_supply_money_winners_or_an_execution_mode(): void
    {
        $class = new ReflectionClass(ChunkedSettlementPreviewService::class);
        $expected = [
            'start' => ['drawId', 'chunkSize'],
            'processChunk' => ['previewId', 'expectedCursor'],
            'finalize' => ['previewId'],
            'summary' => ['previewId'],
            'rulesFingerprint' => [],
        ];

        foreach ($expected as $name => $parameters) {
            $method = $class->getMethod($name);
            $this->assertTrue($method->isPublic());
            $this->assertSame($parameters, array_map(fn ($parameter) => $parameter->getName(), $method->getParameters()));
        }

        $this->assertFalse($class->hasMethod('settle'));
        $this->assertFalse($class->hasMethod('run'));
        $this->assertSame('DRY_RUN', SettlementPreview::MODE);
        $this->assertSame(['*'], (new SettlementPreview)->getGuarded());
        $this->assertSame(['*'], (new SettlementPreviewBet)->getGuarded());
    }

    public function test_only_preview_tables_are_created_or_removed(): void
    {
        $code = $this->codeOnly($this->source('database/migrations/2026_10_14_000100_create_settlement_preview_tables.php'));
        preg_match_all('/Schema::(?:create|dropIfExists)\(\s*\x27([^\x27]+)\x27/', $code, $matches);
        $this->assertSame([
            'settlement_previews', 'settlement_preview_bets', 'settlement_preview_bets', 'settlement_previews',
        ], $matches[1]);
        $this->assertStringNotContainsString('Schema::table(', $code);
        $this->assertStringNotContainsString("constrained('draws')", $code);
        $this->assertStringNotContainsString("constrained('bets')", $code);
    }

    public function test_command_has_no_live_queue_or_force_option_and_is_not_scheduled(): void
    {
        $command = $this->codeOnly($this->source('app/Console/Commands/Lottery/PreviewSettlementCommand.php'));
        $this->assertStringContainsString('lottery:preview-settlement', $command);

        foreach (['--live', '--mode', '--queue', '--force', '--pay'] as $flag) {
            $this->assertStringNotContainsString($flag, $command);
        }

        foreach (['bootstrap/app.php', 'routes/console.php', 'routes/web.php', 'routes/api.php'] as $path) {
            $this->assertStringNotContainsString('lottery:preview-settlement', $this->codeOnly($this->source($path)), $path);
        }
    }

    public function test_pricing_pin_does_not_include_credentials_or_payout_mode_flags(): void
    {
        foreach (ChunkedSettlementPreviewService::RULE_CONFIG_KEYS as $key) {
            $this->assertDoesNotMatchRegularExpression('/secret|password|token|gateway|prize_payout|automation/i', $key);
        }

        $this->assertContains('lottery.markets', ChunkedSettlementPreviewService::RULE_CONFIG_KEYS);
        $this->assertContains('lottery.payouts.multiplier_source', ChunkedSettlementPreviewService::RULE_CONFIG_KEYS);
        $this->assertNotContains('lottery.payouts', ChunkedSettlementPreviewService::RULE_CONFIG_KEYS);
        $this->assertContains('finance.precision', ChunkedSettlementPreviewService::RULE_CONFIG_KEYS);
        $failure = new SettlementPreviewException(SettlementPreviewException::READ_ONLY_MODE, 'Audit only.');
        $this->assertSame(SettlementPreviewException::READ_ONLY_MODE, $failure->errorCode());
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertIsString($source);

        return $source;
    }

    private function dangerousSymbols(string $source): array
    {
        $code = $this->codeOnly($source);
        $symbols = [
            'WalletService', 'WalletHoldService', 'LedgerEntry', 'LedgerAccount', 'FinancialTransaction',
            'RealPrizeSettlementService', 'WithdrawalService', 'PaymentGatewayManager', 'Payout', 'Http', 'Bus', 'Queue',
        ];

        return array_values(array_filter($symbols, fn ($symbol) => preg_match('/\b'.preg_quote($symbol, '/').'\b/', $code) === 1));
    }

    private function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
