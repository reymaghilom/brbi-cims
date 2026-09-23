<?php

namespace App\Console\Commands;

use App\Services\Notifications\ProcessDueCiActivityReminders;
use Illuminate\Console\Command;

class SendCiActivityReminders extends Command
{
    protected $signature = 'ci-activities:send-reminders';

    protected $description = 'Send due CI activity reminders — parent activities and each individual Bank/Asset target — to each activity creator only';

    public function handle(ProcessDueCiActivityReminders $processor): int
    {
        $sent = $processor->process();

        $this->info("Sent {$sent} CI activity reminder(s).");

        return self::SUCCESS;
    }
}
