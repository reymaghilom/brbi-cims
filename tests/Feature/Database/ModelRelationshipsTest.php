<?php

namespace Tests\Feature\Database;

use App\Models\ActivityDefinition;
use App\Models\ActivityNote;
use App\Models\CiActivity;
use App\Models\CibiIncomeSource;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\GeneralIncomeSourceReport;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_client_folder_is_the_root_for_its_phase_two_records(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id, 'created_by' => $investigator->id]);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        $template = IncomeSourceTemplate::factory()->fallback()->create();
        $source = IncomeSource::factory()->create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type]);
        $generalReport = GeneralIncomeSourceReport::create(['income_source_id' => $source->id]);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $source->id, 'uploaded_by' => $investigator->id]);
        $generated = GeneratedReport::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $source->id, 'scope_key' => 'income:'.$source->id, 'generated_by' => $investigator->id]);

        $this->assertTrue($folder->assignedInvestigator->is($investigator));
        $this->assertTrue($folder->information->is($information));
        $this->assertTrue($folder->incomeSources->contains($source));
        $this->assertTrue($source->template->is($template));
        $this->assertTrue($source->generalReport->is($generalReport));
        $this->assertTrue($source->mediaReferences->contains($media));
        $this->assertTrue($source->generatedReports->contains($generated));
    }

    public function test_cibi_summaries_can_reference_an_income_source(): void
    {
        $folder = ClientFolder::factory()->create();
        $source = IncomeSource::factory()->create(['client_folder_id' => $folder->id]);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id]);
        $summary = CibiIncomeSource::create(['cibi_report_id' => $report->id, 'income_source_id' => $source->id, 'source_name' => $source->source_name]);

        $this->assertTrue($report->incomeSourceSummaries->contains($summary));
        $this->assertTrue($summary->incomeSource->is($source));
    }

    public function test_activity_collaboration_is_recorded_without_shared_folder_ownership(): void
    {
        $owner = User::factory()->create();
        $helper = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $owner->id]);
        $definition = ActivityDefinition::factory()->create();
        $activity = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'visited_by' => $helper->full_name, 'updated_by' => $helper->id]);
        $note = ActivityNote::create(['ci_activity_id' => $activity->id, 'user_id' => $helper->id, 'note' => 'Assisted with field validation.']);

        $this->assertTrue($folder->assignedInvestigator->is($owner));
        $this->assertTrue($activity->updater->is($helper));
        $this->assertTrue($note->author->is($helper));
    }
}
