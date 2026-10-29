<?php

namespace App\Console\Commands;

use App\Services\Integrations\N8n\ReminderOutboxDispatcher;
use App\Services\Integrations\N8n\ReminderScheduleOutbox;
use Illuminate\Console\Command;

/**
 * Manual / operator-run delivery of the n8n reminder outbox. Deliberately NOT registered with the
 * scheduler: how delivery is triggered in production is an open Phase 2 decision.
 */
class DeliverN8nReminderEvents extends Command
{
    protected $signature = 'n8n-reminders:deliver
        {--limit=50 : Maximum number of outbox events to process}
        {--retry-failed : Re-queue events that exhausted their attempts before delivering}
        {--reconcile : Re-evaluate every recorded and currently scheduled reminder before delivering}';

    protected $description = 'Deliver pending CI Activity email-reminder schedule events to the n8n webhook (signed, idempotent)';

    public function handle(ReminderOutboxDispatcher $dispatcher, ReminderScheduleOutbox $outbox): int
    {
        if (($error = $dispatcher->configurationError()) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        if ($this->option('reconcile')) {
            $outbox->reconcileAll();
        }

        if ($this->option('retry-failed')) {
            $this->info('Re-queued '.$dispatcher->requeueFailed().' failed event(s).');
        }

        $result = $dispatcher->deliverPending(max(0, (int) $this->option('limit')));

        $this->info(sprintf(
            'Attempted %d, delivered %d, superseded %d, retrying %d, failed %d.',
            $result['attempted'], $result['delivered'], $result['superseded'], $result['retrying'], $result['failed'],
        ));

        return self::SUCCESS;
    }
}
