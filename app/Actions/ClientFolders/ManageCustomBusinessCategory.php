<?php

namespace App\Actions\ClientFolders;

use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CustomBusinessCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add, rename and remove custom "Other Business / Source of Income" checkbox options.
 *
 * Every write here touches the category definition only. No IncomeSource, no BusinessReport and no
 * stored `template_data` is ever read for writing, rewritten or cascade-deleted — a Business Report
 * that selected a category keeps that exact selection whatever happens to the definition.
 */
class ManageCustomBusinessCategory
{
    public function create(User $actor, ClientFolder $folder, string $name): CustomBusinessCategory
    {
        return DB::transaction(function () use ($actor, $folder, $name): CustomBusinessCategory {
            $category = CustomBusinessCategory::create([
                'name' => $name,
                'is_active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->log($actor, $folder, 'custom_business_category.created', 'A custom business option was added.', $category);

            return $category;
        });
    }

    /**
     * Renaming changes the label and nothing else: the row — and therefore the option key every
     * saved report stores — is untouched, so this can never fork one category into two.
     */
    public function rename(User $actor, ClientFolder $folder, CustomBusinessCategory $category, string $name): CustomBusinessCategory
    {
        return DB::transaction(function () use ($actor, $folder, $category, $name): CustomBusinessCategory {
            if ($category->name === $name) {
                // Same convention the rest of the app uses for a submitted no-op: nothing is
                // written, no timestamp moves and no history entry is fabricated.
                throw new NoChangesDetectedException('No changes detected. Nothing needs to be updated.');
            }

            $previous = $category->name;
            $category->forceFill(['name' => $name, 'updated_by' => $actor->id])->save();

            $this->log($actor, $folder, 'custom_business_category.updated', 'A custom business option was renamed.', $category, [
                'previous_name' => $previous,
            ]);

            return $category;
        });
    }

    /**
     * Unused categories are genuinely deleted. One that already appears in saved report data is
     * deactivated instead — it leaves the catalog for new selections while every report that
     * already selected it keeps its data and its label.
     *
     * @return bool True when the row was deleted outright, false when it was deactivated.
     */
    public function remove(User $actor, ClientFolder $folder, CustomBusinessCategory $category): bool
    {
        return DB::transaction(function () use ($actor, $folder, $category): bool {
            $inUse = $this->isUsedByAnySavedReport($category);

            if ($inUse) {
                if ($category->is_active) {
                    $category->forceFill(['is_active' => false, 'updated_by' => $actor->id])->save();
                }

                $this->log($actor, $folder, 'custom_business_category.deactivated', 'A custom business option in use was removed from future selection.', $category);

                return false;
            }

            $this->log($actor, $folder, 'custom_business_category.deleted', 'An unused custom business option was deleted.', $category);
            $category->delete();

            return true;
        });
    }

    /**
     * Has any saved Business Report stored this category's option key? Matched on the key, never on
     * the label, so a renamed category is still recognised as in use.
     */
    public function isUsedByAnySavedReport(CustomBusinessCategory $category): bool
    {
        $key = $category->optionKey();

        return BusinessReport::query()
            ->whereNotNull('template_data')
            ->get(['id', 'template_data'])
            ->contains(function (BusinessReport $report) use ($key): bool {
                $selected = data_get($report->template_data, 'fields.income_sources');

                return is_array($selected) && in_array($key, $selected, true);
            });
    }

    private function log(User $actor, ClientFolder $folder, string $action, string $description, CustomBusinessCategory $category, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'income_sources',
            'description' => $description,
            'metadata' => ['custom_business_category_id' => $category->getKey(), 'name' => $category->name] + $extra,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
