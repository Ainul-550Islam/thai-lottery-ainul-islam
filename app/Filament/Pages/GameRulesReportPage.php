<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\LotteryGameException;
use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameRulesValidator;
use App\Services\LotteryGames\RtpAnalyzer;
use App\Support\Admin\AdminAccess;
use Filament\Pages\Page;

/**
 * The signed-off state of every declared game, in one page, as evidence.
 *
 * WHY THIS IS A PAGE AND NOT A TERMINAL COMMAND
 * The command `lottery-games:validate-rules` already refuses a bad rule set in CI, which
 * is the right place for it before a deploy. But the question asked in an incident review
 * is different and it has a timestamp: WHICH rule set was the platform selling against,
 * who accepted it, and did the deployed configuration still match that acceptance at the
 * time of the draw? That answer has to be readable by a person who was not on the deploy,
 * without shell access, and it has to be readable for a game that is currently broken —
 * which is exactly when the command's non-zero exit is least convenient.
 *
 * WHAT IT SHOWS PER GAME, AND WHY EACH LINE IS EVIDENCE
 *   declared            whether config/lottery_games.php mentions it at all
 *   missing inputs      the literal REQUIRED_INPUT fields an operator still owes us
 *   problems            contradictions in the rule set (rules the engine cannot honour)
 *   checksum stored     the hash an operator stamped
 *   checksum current    the hash of what is deployed right now
 *   verdict             matches / DRIFTED / never verified
 *   RTP per bet type    what the configured multipliers pay back, against the ceiling
 * A drifted checksum is the single most important line on this page: it means the payout
 * table changed after it was signed off, and the lane already refuses to sell on it.
 *
 * WHY NOTHING HERE CAN WRITE
 * There is not one action button, deliberately. Verification is a decision, and a decision
 * belongs on the resource where the operator sees exactly one game, with a confirmation that
 * names what is being accepted. A "verify all" control on a report page would turn a
 * review into a click, and it is the click that the whole checksum mechanism exists to
 * make deliberate.
 *
 * FAIL-CLOSED READ
 * The page renders what the services say even when they throw: a game whose definition is
 * unreadable is listed with the exception's error code rather than omitted, because a game
 * that disappears from an audit page is worse than one that shows an error.
 */
class GameRulesReportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'Rules report';

    protected static ?string $title = 'Digit game rules & RTP report';

    protected static ?int $navigationSort = 42;

    protected static string $view = 'filament.pages.game-rules-report-page';

    /**
     * Who may read the evidence: draw operators, and the accounts that audit money.
     *
     * TWO PERMISSIONS, AND WHY THESE TWO
     * `view draws` is the permission the operating console uses, so anybody who can act on
     * a game can also read why it is or is not selling. `view financial reports` is held by
     * the read-only auditor seat, which is exactly the account that has to be able to answer
     * "which payout table were we selling against, and who accepted it" after the fact. The
     * auditor does NOT hold `view draws` on this platform, so without the second permission
     * the one person whose job is this question would be the one person locked out of the
     * page that answers it — while the page itself can write nothing, so nothing is widened
     * by letting them in.
     */
    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_DRAWS,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    /**
     * The operator's RTP ceiling, for display next to each computed RTP.
     */
    public function ceiling(): string
    {
        return app(GameRegistry::class)->rtpCeiling();
    }

    /**
     * Whether the deployment kill switch is closed.
     */
    public function masterEnabled(): bool
    {
        return app(GameRegistry::class)->masterEnabled();
    }

    /**
     * One row per declared game, or an explicit error row if the game cannot be read.
     *
     * @return list<array<string, mixed>>
     */
    public function games(): array
    {
        $registry = app(GameRegistry::class);
        $availability = app(GameAvailabilityService::class);
        $validator = new GameRulesValidator;
        $analyzer = new RtpAnalyzer;

        $rows = [];

        foreach ($registry->codes() as $code) {
            $governance = $registry->governance($code);
            $row = [
                'code' => $code,
                'name' => $code,
                'registered' => $governance['registered'],
                'declared' => $governance['declared'],
                'status' => $governance['status'],
                'enabled' => $governance['enabled'],
                'missing' => [],
                'problems' => [],
                'warnings' => [],
                'checksum_stored' => $governance['checksum_stored'],
                'checksum_current' => $governance['checksum_current'],
                'verdict' => 'never verified',
                'verified_at' => $governance['verified_at'],
                'verified_by' => $governance['verified_by'],
                'reason' => null,
                'bet_types' => [],
            ];

            switch (true) {
                case $governance['checksum_stored'] === null:
                    $row['verdict'] = 'never verified';
                    break;

                case $governance['checksum_matches']:
                    $row['verdict'] = 'matches deployed rules';
                    break;

                default:
                    $row['verdict'] = 'DRIFTED';
                    break;
            }

            try {
                $definition = $registry->definition($code);
                $row['name'] = $definition->name;

                $report = $validator->validate($definition);
                $row['missing'] = $report['missing'];
                $row['problems'] = $report['problems'];
                $row['warnings'] = $report['warnings'];

                $analysis = $analyzer->analyze($definition, $registry->rtpCeiling());

                foreach ($analysis['bet_types'] as $betType => $entry) {
                    $row['bet_types'][] = [
                        'key' => (string) $betType,
                        'probability' => (string) $entry['probability'],
                        'multiplier' => $entry['multiplier'],
                        'rtp' => $entry['rtp'],
                        'breaches' => in_array($betType, $analysis['breaches'], true),
                    ];
                }

                $row['reason'] = $availability->capability($code)->reason;
            } catch (LotteryGameException $e) {
                // A game that cannot be read is shown WITH its error, never hidden.
                $row['reason'] = $e->errorCode();
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
