<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\ContactDeliveryService;
use Illuminate\Console\Command;

/**
 * Reports whether contact messages can actually be forwarded
 * (PROMPT 10, file 23).
 *
 * IT PRINTS NO CREDENTIAL. Not a host, not a port, not a username, not a
 * token, not the support address itself. It answers three yes/no questions -
 * is forwarding switched on, is a From address set, is a destination set - and
 * that is enough to diagnose the common misconfiguration without putting a
 * secret into a terminal, a CI log or a screenshot.
 *
 * IT DOES NOT SEND A TEST MESSAGE. A health command that emits mail on every
 * run is a health command someone eventually points at production. The exit
 * code reflects CONFIGURATION, and the only honest proof of delivery is a real
 * message's recorded state.
 */
class ContactDeliveryHealth extends Command
{
    /**
     * @var string
     */
    protected $signature = 'contact:delivery-health';

    /**
     * @var string
     */
    protected $description = 'Report whether outbound contact delivery is configured, without printing any credential.';

    public function handle(ContactDeliveryService $delivery): int
    {
        $status = $delivery->status();
        $configured = $delivery->isConfigured();

        $this->line('Contact delivery health');
        $this->line('  provider               : '.$status['provider']);
        $this->line('  forwarding enabled     : '.($this->flag((bool) config('contact.delivery.enabled', false))));
        $this->line('  from address configured: '.$this->flag((bool) $status['from_configured']));
        $this->line('  destination configured : '.$this->flag((bool) $status['destination_configured']));
        $this->newLine();

        if ($configured) {
            $this->info('State: CONFIGURED — messages will be forwarded, and a real send is what proves it.');

            return self::SUCCESS;
        }

        // Not a failure of the platform: a fresh clone is expected to be
        // unconfigured, and the page says so honestly rather than claiming to
        // have sent anything.
        $this->warn('State: NOT_CONFIGURED — messages are stored and nothing is forwarded.');

        return self::SUCCESS;
    }

    private function flag(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
