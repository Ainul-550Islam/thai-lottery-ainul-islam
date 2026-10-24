<?php

declare(strict_types=1);

/**
 * Isolated CLI worker for multi-process GLO L6 ticket allocation concurrency tests.
 *
 * Arguments:
 *   argv[1]: sqlite database file path
 *   argv[2]: buyer user_id
 *   argv[3]: draw_id
 *   argv[4]: ticket_number (6 digits)
 *   argv[5]: set_series (integer)
 *   argv[6]: idempotency_key
 */

use App\Models\User;
use App\Services\Lottery\GloL6TransactionalPurchaseService;
use Illuminate\Contracts\Console\Kernel;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';

$dbFile = (string) ($argv[1] ?? '');
$userId = (int) ($argv[2] ?? 0);
$drawId = (int) ($argv[3] ?? 0);
$ticketNumber = (string) ($argv[4] ?? '000000');
$series = (int) ($argv[5] ?? 1);
$idempotencyKey = (string) ($argv[6] ?? '');

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$dbFile);
putenv('DB_BUSY_TIMEOUT=15000');
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbFile;
$_ENV['DB_BUSY_TIMEOUT'] = '15000';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $dbFile,
    'database.connections.sqlite.busy_timeout' => 15000,
    'real_money.enabled' => true,
    'glo.l6.purchase.enabled' => true,
    'glo.l6.purchase.inventory_provider_mode' => 'sandbox_seeded',
    'glo.l6.purchase.require_official_result_source' => true,
    'glo.official_source.mode' => 'official',
    'glo.official_source.base_url' => 'https://www.glo.or.th',
    'glo.official_source.latest_lottery_path' => '/api/lottery/getLatestLottery',
    'glo.official_source.lottery_result_path' => '/api/checking/getLotteryResult',
    'glo.sources.fall_through_to_fixture' => false,
    'glo.result_sources.fall_through_to_fixture' => false,
]);

try {
    /** @var User $buyer */
    $buyer = User::query()->findOrFail($userId);

    $result = $app->make(GloL6TransactionalPurchaseService::class)->purchaseImmediate(
        buyer: $buyer,
        drawId: $drawId,
        ticketNumber: $ticketNumber,
        series: $series,
        idempotencyKey: $idempotencyKey,
    );

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'ticket_id' => (int) $result['ticket']->getKey(),
        'ticket_reference' => (string) $result['ticket']->ticket_reference,
        'reservation_reference' => (string) $result['reservation']->reservation_reference,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'code' => method_exists($e, 'errorCode') ? $e->errorCode() : $e->getCode(),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
