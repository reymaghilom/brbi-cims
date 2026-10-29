<?php

namespace App\Observers;

use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\Integrations\N8n\ReminderScheduleOutbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Feeds the n8n reminder outbox from model events, so EVERY Eloquent save/delete path of a CI
 * Activity or target (create, update, reschedule, complete, reopen, delete, bulk complete) is
 * covered without touching those Actions. The events fire inside the Actions' own transactions,
 * which is what makes the outbox transactional. Does nothing while the integration is disabled.
 *
 * Rows removed by database cascades fire no model event: a deleted parent's targets, and
 * everything under a purged folder or a removed Co-Maker. Those are reconciled from the recorded
 * state instead. The operational data reset calls reconcileMissing() itself.
 */
class ReminderScheduleObserver
{
    public function __construct(private readonly ReminderScheduleOutbox $outbox) {}

    public function saved(Model $model): void
    {
        if (! ReminderScheduleOutbox::enabled()) {
            return;
        }

        match (true) {
            $model instanceof CiActivity => $this->syncActivityAndChildren($model),
            $model instanceof CiActivityBankTarget => $this->outbox->syncBankTarget($model),
            $model instanceof CiActivityAssetTarget => $this->outbox->syncAssetTarget($model),
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if (! ReminderScheduleOutbox::enabled()) {
            return;
        }

        match (true) {
            $model instanceof CiActivity => $this->syncActivityAndChildren($model),
            $model instanceof CiActivityBankTarget => $this->outbox->syncBankTarget($model),
            $model instanceof CiActivityAssetTarget => $this->outbox->syncAssetTarget($model),
            $model instanceof CoMaker => $this->outbox->reconcileMissing((int) $model->client_folder_id),
            default => null,
        };
    }

    /** Only a PERMANENT folder delete cascades its activities away; a soft delete keeps them. */
    public function forceDeleted(Model $model): void
    {
        if (ReminderScheduleOutbox::enabled() && $model instanceof ClientFolder) {
            $this->outbox->reconcileMissing((int) $model->getKey());
        }
    }

    private function syncActivityAndChildren(CiActivity $activity): void
    {
        $this->outbox->syncActivity($activity);

        // A deleted (or restored) parent changes whether its targets are remindable.
        if (! $activity->exists || $activity->trashed() || $activity->wasChanged('deleted_at')) {
            $this->outbox->resyncChildrenOf((int) $activity->getKey());
        }
    }
}
