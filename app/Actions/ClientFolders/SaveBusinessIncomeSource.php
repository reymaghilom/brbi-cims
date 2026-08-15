<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveBusinessIncomeSource
{
    private const SOURCE_FIELDS = ['source_name', 'business_name', 'contribution_rank', 'estimated_monthly_contribution', 'is_primary', 'branch_name', 'account_officer_name'];

    private const REPORT_FIELDS = ['business_name', 'report_category', 'start_date', 'submitted_date', 'main_business_address', 'previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'registered_owner', 'relationship_to_borrower', 'year_established', 'length_of_stay_months', 'monthly_rent', 'ownership_type', 'rented_from', 'business_type', 'scale', 'informant', 'report_remarks', 'template_data'];

    private const SECTIONS = [
        'branches' => ['branches', ['location', 'is_declared', 'is_inspected', 'reason_not_inspected', 'frontage_meters', 'total_area_square_meters', 'is_air_conditioned', 'operating_days_hours', 'shifts_count', 'employees_per_shift', 'average_sales_per_shift', 'inventory_level', 'monthly_rent', 'years_in_area', 'nearby_brands'], 'location'],
        'products' => ['products', ['product_name', 'unit_size', 'selling_price', 'stock_level', 'is_top_seller'], 'product_name'],
        'suppliers' => ['suppliers', ['supplier_name', 'office_location', 'contact_information', 'is_confirmed', 'years_transacting', 'payment_performance', 'remarks'], 'supplier_name'],
        'observations' => ['observations', ['observation_code', 'question_snapshot', 'answer', 'remarks'], 'observation_code'],
        'competitors' => ['competitors', ['name', 'location', 'notes'], 'name'],
    ];

    public function __construct(private readonly IncomeSourcesCompletionEvaluator $completion, private readonly ClientProgressService $progress) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $source, $data): IncomeSource {
            $source->fill(Arr::only($data, self::SOURCE_FIELDS));
            $source->state = $data['intent'] === 'complete' ? RecordState::Complete : RecordState::Draft;
            $source->last_edited_by = $actor->id;
            $source->revision++;
            $source->save();

            $report = $source->businessReport()->firstOrCreate([], ['business_name' => $source->business_name ?: $source->source_name, 'report_category' => $source->template->business_category ?: $source->template->name]);
            $report->fill(Arr::only($data, self::REPORT_FIELDS));
            $report->save();
            $tags = $source->template->businessReportSchema() !== [] ? [] : ($source->template->compatibility_tags ?? []);
            $changes = [];

            foreach (self::SECTIONS as $input => [$relation, $fields, $required]) {
                if (in_array($input, $tags, true)) {
                    $changes[$input] = $this->sync($report->{$relation}(), $data[$input] ?? [], $fields, $required);
                }
            }
            if (in_array('tenants', $tags, true)) {
                $changes['tenants'] = $this->syncTenants($report, $data['tenants'] ?? []);
            }
            if (in_array('properties', $tags, true)) {
                $changes['properties'] = $this->sync($report->properties(), $data['properties'] ?? [], ['property_type', 'is_declared', 'is_inspected', 'reason_not_inspected', 'units_available', 'units_with_tenants', 'location', 'area_square_meters', 'has_contract', 'remarks'], 'property_type');
            }

            $report->update([
                'properties_declared' => $report->properties()->where('is_declared', true)->count(),
                'properties_inspected' => $report->properties()->where('is_inspected', true)->count(),
                'branches_declared' => $report->branches()->where('is_declared', true)->count(),
                'branches_inspected' => $report->branches()->where('is_inspected', true)->count(),
            ]);
            $this->completion->evaluateSource($source->load('template', 'businessReport'));
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'business_report.updated', 'module' => 'income_sources',
                'description' => 'A dedicated business report was updated.',
                'metadata' => ['income_source_id' => $source->id, 'business_report_id' => $report->id, 'revision' => $source->revision, 'state' => $source->state->value, 'child_changes' => $changes],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.updated', 'module' => 'income_sources',
                'description' => 'An income source was updated.',
                'metadata' => ['income_source_id' => $source->id, 'template_type' => $source->template_type, 'revision' => $source->revision, 'state' => $source->state->value],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            return $source->refresh();
        });
    }

    private function sync(HasMany $relation, array $rows, array $fields, string $required): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        if ($relation->getRelated()->getTable() === 'business_observations') {
            $relation->whereIn('id', collect($rows)->pluck('id')->filter())->get()->each(fn ($row) => $row->update(['observation_code' => '__pending_'.$row->id]));
        }
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $payload = Arr::only($row, $fields) + ['sort_order' => $index + 1];
            if ($id) {
                $model = $relation->findOrFail($id);
                $delete ? $model->delete() : $model->update($payload);
                $changes[$delete ? 'deleted' : 'updated']++;
            } elseif (! $delete && filled($row[$required] ?? null)) {
                $relation->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }

    private function syncTenants(BusinessReport $report, array $rows): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $property = filled($row['business_property_id'] ?? null) ? $report->properties()->findOrFail((int) $row['business_property_id']) : null;
            $payload = Arr::only($row, ['tenant_name', 'monthly_rent', 'years_renting', 'has_contract', 'contact_details', 'remarks']) + ['sort_order' => $index + 1];
            if ($id) {
                $tenant = $report->properties()->with('tenants')->get()->pluck('tenants')->flatten()->firstWhere('id', $id);
                abort_if($tenant === null, 404);
                if ($delete) {
                    $tenant->delete();
                } else {
                    $tenant->business_property_id = $property->id;
                    $tenant->fill($payload)->save();
                }
                $changes[$delete ? 'deleted' : 'updated']++;
            } elseif (! $delete && $property && filled($row['tenant_name'] ?? null)) {
                $property->tenants()->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }
}
