<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Reports\CibiExcelExporter;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CI/BI Report → I. VALIDATED PERSONAL INFORMATION, residence status.
 *
 * Three separate concepts that must never bleed into one another:
 *   A. the PRESENT address status  (Owned / Mortgaged / Rented / Living with Parents, required)
 *   B. the PARENTS' house status   (Owned / Mortgaged / Rented, optional, only while A is
 *                                   "Living with Parents", never with a lender/lessor of its own)
 *   C. the OTHER residence status  (Owned / Mortgaged / Rented, optional, independent of both)
 *
 * All three live in the existing personal_snapshot JSON — no schema change — and every optional
 * one accepts blank. Switching A away from a state must not leave B or the "from" detail behind
 * to contradict the saved report.
 */
class CibiResidenceStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_form_offers_the_four_present_address_choices_and_both_optional_secondary_statuses(): void
    {
        [$ci, $folder] = $this->folder();

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        foreach (['Owned', 'Mortgaged', 'Rented', 'Living with Parents'] as $status) {
            $this->assertStringContainsString('name="personal_snapshot[residence_status]" value="'.$status.'"', $page);
        }
        // The one optional "from" detail names itself after the chosen status.
        $this->assertStringContainsString('data-residence-from-label', $page);
        $this->assertStringContainsString('Mortgaged From', $page);
        $this->assertStringContainsString('data-monthly-residence-label', $page);
        $this->assertStringContainsString('Monthly Mortgage Payment', $page);
        $this->assertStringContainsString("monthlyResidenceLabel.textContent = rented ? 'Monthly Rent' : 'Monthly Mortgage Payment'", file_get_contents(base_path('resources/js/app.js')));

        // Both secondary statuses offer a real blank option, and neither asks for a lender/lessor.
        foreach (['parents_house_status', 'other_residence_status'] as $field) {
            $this->assertStringContainsString('name="personal_snapshot['.$field.']" value=""', $page);
            foreach (['Owned', 'Mortgaged', 'Rented'] as $status) {
                $this->assertStringContainsString('name="personal_snapshot['.$field.']" value="'.$status.'"', $page);
            }
        }
        // Parents' house status is hidden until it applies.
        $this->assertMatchesRegularExpression('/data-parents-house-field\s+hidden/', $page);
    }

    #[DataProvider('residenceCases')]
    public function test_every_present_address_combination_saves_and_reopens(array $snapshot, array $expected): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, $snapshot);

        $saved = CibiReport::whereBelongsTo($folder)->sole()->personal_snapshot;
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $saved[$key] ?? null, $key);
        }

        // …and the reopened form re-checks exactly what was saved.
        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$expected['residence_status'].'" checked', $page);
        foreach (['parents_house_status', 'other_residence_status'] as $field) {
            $this->assertStringContainsString(
                'name="personal_snapshot['.$field.']" value="'.($expected[$field] ?? '').'" checked',
                $page,
                $field,
            );
        }
        if (($expected['residence_status_from'] ?? null) !== null) {
            $this->assertStringContainsString('value="'.$expected['residence_status_from'].'"', $page);
        }
        if (($expected['monthly_rent'] ?? null) !== null) {
            $this->assertStringContainsString('value="'.$expected['monthly_rent'].'"', $page);
        }
    }

    public static function residenceCases(): array
    {
        return [
            'owned' => [
                ['residence_status' => 'Owned'],
                ['residence_status' => 'Owned', 'residence_status_from' => null, 'parents_house_status' => null],
            ],
            'mortgaged with blank from' => [
                ['residence_status' => 'Mortgaged', 'residence_status_from' => ''],
                ['residence_status' => 'Mortgaged', 'residence_status_from' => null],
            ],
            'mortgaged with optional details' => [
                ['residence_status' => 'Mortgaged', 'residence_status_from' => 'FICCO', 'monthly_rent' => '5,000'],
                ['residence_status' => 'Mortgaged', 'residence_status_from' => 'FICCO', 'monthly_rent' => '5,000'],
            ],
            'rented with blank from' => [
                ['residence_status' => 'Rented', 'residence_status_from' => ''],
                ['residence_status' => 'Rented', 'residence_status_from' => null],
            ],
            'rented with optional details' => [
                ['residence_status' => 'Rented', 'residence_status_from' => 'Juan Dela Cruz', 'monthly_rent' => '4,500'],
                ['residence_status' => 'Rented', 'residence_status_from' => 'Juan Dela Cruz', 'monthly_rent' => '4,500'],
            ],
            'with parents, no secondary choice' => [
                ['residence_status' => 'Living with Parents', 'parents_house_status' => ''],
                ['residence_status' => 'Living with Parents', 'parents_house_status' => null],
            ],
            'with parents, owned' => [
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Owned'],
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Owned'],
            ],
            'with parents, mortgaged' => [
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Mortgaged'],
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Mortgaged', 'residence_status_from' => null],
            ],
            'with parents, rented' => [
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Rented'],
                ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Rented', 'residence_status_from' => null],
            ],
            'other residence owned' => [
                ['residence_status' => 'Owned', 'other_residence_status' => 'Owned'],
                ['residence_status' => 'Owned', 'other_residence_status' => 'Owned'],
            ],
            'other residence mortgaged needs no source' => [
                ['residence_status' => 'Owned', 'other_residence_status' => 'Mortgaged'],
                ['residence_status' => 'Owned', 'other_residence_status' => 'Mortgaged'],
            ],
            'other residence rented needs no landlord' => [
                ['residence_status' => 'Rented', 'other_residence_status' => 'Rented', 'residence_status_from' => 'Landlord'],
                ['residence_status' => 'Rented', 'other_residence_status' => 'Rented', 'residence_status_from' => 'Landlord'],
            ],
        ];
    }

    public function test_switching_the_present_address_clears_the_stale_secondary_values(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, ['residence_status' => 'Living with Parents', 'parents_house_status' => 'Rented']);
        $report = CibiReport::whereBelongsTo($folder)->sole();
        $this->assertSame('Rented', $report->personal_snapshot['parents_house_status']);

        // The encoder switches to Mortgaged and names the lender; the old parents' status must go.
        $this->save($ci, $folder, null, ['residence_status' => 'Mortgaged', 'residence_status_from' => 'ABC Cooperative', 'monthly_rent' => '6,500', 'parents_house_status' => 'Rented'], $report->revision);
        $saved = $report->fresh()->personal_snapshot;
        $this->assertSame('Mortgaged', $saved['residence_status']);
        $this->assertSame('ABC Cooperative', $saved['residence_status_from']);
        $this->assertSame('6,500', $saved['monthly_rent']);
        $this->assertNull($saved['parents_house_status']);

        // Switching to Owned must in turn drop the mortgagee — no "Owned, mortgaged from X".
        $this->save($ci, $folder, null, ['residence_status' => 'Owned', 'residence_status_from' => 'ABC Cooperative', 'monthly_rent' => '6,500'], $report->fresh()->revision);
        $saved = $report->fresh()->personal_snapshot;
        $this->assertSame('Owned', $saved['residence_status']);
        $this->assertNull($saved['residence_status_from']);
        $this->assertNull($saved['monthly_rent']);
        $this->assertNull($saved['parents_house_status']);
        // The independent Other Residence status is NOT collateral damage.
        $this->save($ci, $folder, null, ['residence_status' => 'Owned', 'other_residence_status' => 'Mortgaged'], $report->fresh()->revision);
        $this->assertSame('Mortgaged', $report->fresh()->personal_snapshot['other_residence_status']);
    }

    public function test_the_optional_statuses_reject_values_outside_their_own_vocabulary(): void
    {
        [$ci, $folder] = $this->folder();

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload([
            'residence_status' => 'Living with Parents',
            'parents_house_status' => 'Living with Parents',
        ]))->assertSessionHasErrors(['personal_snapshot.parents_house_status']);

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload([
            'residence_status' => 'Owned',
            'other_residence_status' => 'Squatting',
        ]))->assertSessionHasErrors(['personal_snapshot.other_residence_status']);

        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_residence_status_stays_scoped_to_the_applicant_and_each_co_maker(): void
    {
        [$ci, $folder] = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $this->save($ci, $folder, null, ['residence_status' => 'Owned']);
        $this->save($ci, $folder, $coMakerA->id, ['residence_status' => 'Mortgaged', 'residence_status_from' => 'MCCB', 'monthly_rent' => '6,500']);
        $this->save($ci, $folder, $coMakerB->id, ['residence_status' => 'Rented', 'residence_status_from' => 'Landlord B', 'monthly_rent' => '3,000']);

        $applicant = $folder->cibiReport()->whereNull('co_maker_id')->sole()->personal_snapshot;
        $reportA = $folder->cibiReport()->where('co_maker_id', $coMakerA->id)->sole()->personal_snapshot;
        $reportB = $folder->cibiReport()->where('co_maker_id', $coMakerB->id)->sole()->personal_snapshot;

        $this->assertSame(['Owned', null, null, null], [$applicant['residence_status'], $applicant['residence_status_from'], $applicant['monthly_rent'], $applicant['parents_house_status']]);
        $this->assertSame(['Mortgaged', 'MCCB', '6,500', null], [$reportA['residence_status'], $reportA['residence_status_from'], $reportA['monthly_rent'], $reportA['parents_house_status']]);
        $this->assertSame(['Rented', 'Landlord B', '3,000', null], [$reportB['residence_status'], $reportB['residence_status_from'], $reportB['monthly_rent'], $reportB['parents_house_status']]);

        // Each person's own form reopens with only its own answers.
        $coMakerPage = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMakerA->id)->assertOk()->getContent();
        $this->assertStringContainsString('value="MCCB"', $coMakerPage);
        $this->assertStringContainsString('value="6,500"', $coMakerPage);
        $applicantPage = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringNotContainsString('MCCB', $applicantPage);
        $this->assertStringNotContainsString('Landlord B', $applicantPage);
    }

    public function test_the_official_outputs_carry_the_same_residence_meaning(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, [
            'residence_status' => 'Living with Parents',
            'parents_house_status' => 'Mortgaged',
            'other_residences' => 'Ancestral house in Bugo',
            'other_residence_status' => 'Owned',
        ]);
        $this->actingAs($ci);

        // Web Preview + PDF share this data and blade: the parents' group is marked on the
        // LIVING WITH PARENTS line only because that status is the one selected.
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $this->assertSame('Living with Parents', $document['cibi']['personal']['residence_status']);
        $this->assertSame('Mortgaged', $document['cibi']['personal']['parents_house_status']);
        $this->assertSame('Owned', $document['cibi']['personal']['other_residence_status']);

        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        $this->assertStringContainsString('cibi-parents-house-choices', $html);
        $this->assertStringContainsString('( ✓ ) LIVING WITH PARENTS', $html);
        $this->assertStringContainsString('Ancestral house in Bugo', $html);

        // DOCX prints the three concepts as three separate rows.
        $personal = collect($document['sections'])->firstWhere('title', 'I. Validated Personal Information');
        $rows = collect($personal['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('Mortgaged', $rows["Parents' House Status"]);
        $this->assertSame('Owned', $rows['Other Residence Status']);

        // XLSX row 15 marks LIVING W PARENTS and, separately, the parents' house status.
        $sheet = $this->workbook($folder);
        $c15 = (string) $sheet->getCell('C15')->getValue();
        $this->assertStringContainsString('LIVING W PARENTS', $c15);
        $this->assertStringContainsString('( ✓ ) MORTGAGED', $c15);
        $this->assertStringNotContainsString('( ✓ ) OWNED', $c15);
        $this->assertStringContainsString('Owned - Ancestral house in Bugo', (string) $sheet->getCell('R15')->getValue());
    }

    public function test_web_preview_section_one_follows_the_excel_row_arrangement_without_missing_or_duplicate_values(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, [
            'residence_status' => 'Mortgaged',
            'residence_status_from' => 'FICCO ARRANGEMENT VALUE',
            'monthly_rent' => '100000',
            'other_residence_status' => 'Owned',
            'other_residences' => 'OTHER RESIDENCE ARRANGEMENT VALUE',
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        preg_match('/<table class="cibi-form-table cibi-personal">.*?<\/table>/s', $html, $sectionMatch);
        $this->assertNotEmpty($sectionMatch, 'Section I table was not rendered.');
        $section = $sectionMatch[0];
        preg_match_all('/<tr[^>]*>.*?<\/tr>/s', $section, $rowMatches);

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $pdfHtml = view('reports.official.document', [
            'document' => $document,
            'pdfMode' => true,
            'clientFolder' => $folder,
            'type' => OfficialReportType::Cibi,
            'source' => null,
            'personParams' => [],
        ])->render();
        preg_match('/<table class="cibi-form-table cibi-personal">.*?<\/table>/s', $pdfHtml, $pdfSectionMatch);
        $this->assertSame($section, $pdfSectionMatch[0] ?? null, 'Web Preview and PDF must render Section I from the same authoritative layout.');

        $sheet = $this->workbook($folder);
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('Y14')->getDataType());
        $this->assertSame(100000.0, (float) $sheet->getCell('Y14')->getValue());
        $this->assertSame('100,000', $sheet->getCell('Y14')->getFormattedValue());
        $this->assertSame('#,##0', $sheet->getStyle('Y14')->getNumberFormat()->getFormatCode());

        $rowContaining = function (string $label) use ($rowMatches): string {
            $row = collect($rowMatches[0])->first(fn (string $candidate): bool => str_contains($candidate, $label));
            $this->assertNotNull($row, "Missing Section I row containing {$label}.");

            return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($row)));
        };

        $this->assertStringContainsString('AGE:', $rowContaining('NAME OF CLIENT:'));
        $this->assertStringContainsString('AGE:', $rowContaining("SPOUSE'S NAME:"));
        $this->assertStringContainsString('LENGTH OF STAY:', $rowContaining('PRESENT ADDRESS:'));

        $residenceRow = $rowContaining('MORTGAGED FROM:');
        foreach (['OWNED', 'MORTGAGED FROM:', 'RENTED FROM:', 'FICCO ARRANGEMENT VALUE', 'PHP MONTHLY:', '100,000'] as $value) {
            $this->assertStringContainsString($value, $residenceRow);
        }
        $this->assertStringNotContainsString('LIVING WITH PARENTS:', $residenceRow);
        $this->assertMatchesRegularExpression('/<td colspan="2" class="cibi-residence-source">FICCO ARRANGEMENT VALUE<\/td>/', $section);
        $this->assertDoesNotMatchRegularExpression('/<span>[^<]*MORTGAGED FROM:[^<]*FICCO ARRANGEMENT VALUE<\/span>/', $section);

        $parentsRow = $rowContaining('LIVING WITH PARENTS:');
        foreach (['OWNED', 'MORTGAGED', 'RENTED', 'OTHER RESIDENCES:', 'Owned - OTHER RESIDENCE ARRANGEMENT VALUE'] as $value) {
            $this->assertStringContainsString($value, $parentsRow);
        }
        $this->assertStringContainsString('.cibi-parents-house-choices{display:inline-flex;flex-wrap:nowrap;align-items:center;gap:.09in}', $html);
        $this->assertMatchesRegularExpression('/<span class="cibi-parents-house-choices"><span>[^<]*OWNED<\/span><span>[^<]*MORTGAGED<\/span><span>[^<]*RENTED<\/span><\/span>/', $section);

        $this->assertStringContainsString('.cibi-home-condition-choices{flex-wrap:nowrap;column-gap:.06in;font-size:.9em}', $html);
        $this->assertMatchesRegularExpression('/<tr><th>HOME CONDITION:<\/th>.*?<div class="cibi-choice-list cibi-home-condition-choices"><span>[^<]*NEW<\/span><span>[^<]*SLIGHTLY NEW<\/span><span>[^<]*ANCESTRAL<\/span><span>[^<]*APARTMENT<\/span><span>[^<]*DORM<\/span><span>[^<]*SHANTY<\/span><\/div>.*?<th># OF STOREYS:<\/th>/s', $section);

        foreach ([
            'HOME CONDITION:' => '# OF STOREYS:',
            'MATERIAL COST:' => 'LIVING CONDITION:',
            'PREVIOUS ADDRESS:' => 'LENGTH OF STAY:',
            "PARENTS' ADDRESS:" => '# OF DEPENDENTS:',
            'CIVIL STATUS:' => 'IF SEPARATED, YEAR:',
            'LIFESTYLE:' => 'VEHICLES OWNED:',
        ] as $left => $right) {
            $this->assertStringContainsString($right, $rowContaining($left));
        }

        $orderedLabels = [
            'NAME OF CLIENT:', "SPOUSE'S NAME:", 'PRESENT ADDRESS:', 'MORTGAGED FROM:',
            'LIVING WITH PARENTS:', 'HOME CONDITION:', 'MATERIAL COST:', 'PREVIOUS ADDRESS:',
            "PARENTS' ADDRESS:", 'CIVIL STATUS:', 'REPUTATION:', 'BRGY LEVEL FINDINGS:',
            'COURT BACKGROUND:', 'LIFESTYLE:', 'VALIDATED CONTACT NUMBER(S)/EMAIL:', 'OTHER REMARKS:',
        ];
        $lastPosition = -1;
        foreach ($orderedLabels as $label) {
            $position = strpos($section, $label);
            $this->assertNotFalse($position, "Missing Section I label {$label}.");
            $this->assertGreaterThan($lastPosition, $position, "Section I label {$label} is out of order.");
            $lastPosition = $position;
        }

        foreach (['FICCO ARRANGEMENT VALUE', '100,000', 'OTHER RESIDENCE ARRANGEMENT VALUE'] as $value) {
            $this->assertSame(1, substr_count($section, $value), "{$value} should appear exactly once in Section I.");
        }
    }

    public function test_rearranged_web_preview_keeps_applicant_and_exact_co_maker_personal_information_isolated(): void
    {
        [$ci, $folder] = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OUTPUT CO MAKER']);
        $this->save($ci, $folder, null, [
            'residence_status' => 'Owned',
            'other_residences' => 'APPLICANT OUTPUT RESIDENCE',
        ]);
        $this->save($ci, $folder, $coMaker->id, [
            'residence_status' => 'Rented',
            'residence_status_from' => 'CO MAKER LANDLORD',
            'monthly_rent' => '3,765',
            'other_residences' => 'CO MAKER OTHER RESIDENCE',
        ]);

        $applicantHtml = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        $coMakerHtml = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();

        $this->assertStringContainsString('APPLICANT OUTPUT RESIDENCE', $applicantHtml);
        $this->assertStringNotContainsString('CO MAKER LANDLORD', $applicantHtml);
        $this->assertStringContainsString('CO MAKER LANDLORD', $coMakerHtml);
        $this->assertStringContainsString('3,765', $coMakerHtml);
        $this->assertStringContainsString('<td colspan="2" class="cibi-residence-source">CO MAKER LANDLORD</td>', $coMakerHtml);
        $this->assertStringNotContainsString('APPLICANT OUTPUT RESIDENCE', $coMakerHtml);
    }

    public function test_mortgage_and_rental_amounts_share_the_official_php_monthly_field(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, [
            'residence_status' => 'Mortgaged',
            'residence_status_from' => 'FICCO',
            'monthly_rent' => '5,000',
        ]);
        $this->actingAs($ci);

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $rows = collect(collect($document['sections'])->firstWhere('title', 'I. Validated Personal Information')['rows'])
            ->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('5,000', $rows['Php Monthly']);
        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        $this->assertStringContainsString('PHP MONTHLY:', $html);
        $this->assertStringContainsString('5,000', $html);
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td>5,000</td>', $html);
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td>5,000</td>', $this->officialPdfHtml($folder));
        $this->assertStringContainsString('<td colspan="2" class="cibi-residence-source">FICCO</td>', $html);
        $this->assertDoesNotMatchRegularExpression('/<span>[^<]*MORTGAGED FROM:[^<]*FICCO<\/span>/', $html);
        $sheet = $this->workbook($folder);
        $this->assertStringContainsString('MORTGAGED FROM:', (string) $sheet->getCell('C14')->getValue());
        $this->assertStringNotContainsString('FICCO', (string) $sheet->getCell('C14')->getValue());
        $this->assertSame('FICCO', (string) $sheet->getCell('L14')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('Y14')->getDataType());
        $this->assertSame(5000.0, (float) $sheet->getCell('Y14')->getValue());
        $this->assertSame('5,000', $sheet->getCell('Y14')->getFormattedValue());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('Y14')->getAlignment()->getHorizontal());
        $this->assertSame('#,##0', $sheet->getStyle('Y14')->getNumberFormat()->getFormatCode());
        $this->assertSame(Alignment::HORIZONTAL_GENERAL, $sheet->getStyle('L14')->getAlignment()->getHorizontal());
        $this->assertContains('Y14:AA14', $sheet->getMergeCells());

        $report = $folder->cibiReport()->whereNull('co_maker_id')->sole();
        $this->save($ci, $folder, null, [
            'residence_status' => 'Rented',
            'residence_status_from' => 'Juan Dela Cruz',
            'monthly_rent' => '12,500',
        ], $report->revision);
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $this->assertSame('12,500', $document['cibi']['personal']['monthly_rent']);
        $rows = collect(collect($document['sections'])->firstWhere('title', 'I. Validated Personal Information')['rows'])
            ->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('12,500', $rows['Php Monthly']);
        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td>12,500</td>', $html);
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td>12,500</td>', $this->officialPdfHtml($folder));
        $this->assertStringContainsString('<td colspan="2" class="cibi-residence-source">Juan Dela Cruz</td>', $html);
        $this->assertDoesNotMatchRegularExpression('/<span>[^<]*RENTED FROM:[^<]*Juan Dela Cruz<\/span>/', $html);
        $sheet = $this->workbook($folder);
        $this->assertStringContainsString('RENTED FROM:', (string) $sheet->getCell('C14')->getValue());
        $this->assertStringNotContainsString('Juan Dela Cruz', (string) $sheet->getCell('C14')->getValue());
        $this->assertSame('Juan Dela Cruz', (string) $sheet->getCell('L14')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('Y14')->getDataType());
        $this->assertSame(12500.0, (float) $sheet->getCell('Y14')->getValue());
        $this->assertSame('12,500', $sheet->getCell('Y14')->getFormattedValue());
        $this->assertSame('#,##0', $sheet->getStyle('Y14')->getNumberFormat()->getFormatCode());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('Y14')->getAlignment()->getHorizontal());

        $report->refresh();
        $this->save($ci, $folder, null, [
            'residence_status' => 'Owned',
            'residence_status_from' => 'stale source',
            'monthly_rent' => '9,999',
        ], $report->revision);
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $this->assertSame('N/A', $document['cibi']['personal']['residence_status_from']);
        $this->assertSame('N/A', $document['cibi']['personal']['monthly_rent']);
        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertOk()->getContent();
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td></td>', $html);
        $this->assertStringContainsString('<th>PHP MONTHLY:</th><td></td>', $this->officialPdfHtml($folder));
        $this->assertStringContainsString('<td colspan="2" class="cibi-residence-source">N/A</td>', $html);
        $this->assertStringNotContainsString('stale source', $html);
        $this->assertStringNotContainsString('9,999', $html);
        $sheet = $this->workbook($folder);
        $this->assertStringContainsString('OWNED', (string) $sheet->getCell('C14')->getValue());
        $this->assertSame('N/A', (string) $sheet->getCell('L14')->getValue());
        $this->assertNull($sheet->getCell('Y14')->getValue());
        $this->assertSame('', $sheet->getCell('Y14')->getFormattedValue());
        $this->assertSame('#,##0', $sheet->getStyle('Y14')->getNumberFormat()->getFormatCode());
        $this->assertStringNotContainsString('stale source', (string) $sheet->getCell('C14')->getValue());
    }

    public function test_a_blank_parents_status_is_never_fabricated_in_the_outputs(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, ['residence_status' => 'Living with Parents']);
        $this->actingAs($ci);

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $personal = collect($document['sections'])->firstWhere('title', 'I. Validated Personal Information');
        $rows = collect($personal['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('N/A', $rows["Parents' House Status"]);
        $this->assertSame('N/A', $rows['Other Residence Status']);

        // No box on the parents' group is ticked when the CI left it unanswered.
        $sheet = $this->workbook($folder);
        $c15 = (string) $sheet->getCell('C15')->getValue();
        $this->assertSame('N/A', (string) $sheet->getCell('L14')->getValue());
        $this->assertNull($sheet->getCell('Y14')->getValue());
        $this->assertStringContainsString('( ✓ ) LIVING W PARENTS', $c15);
        $this->assertStringNotContainsString('( ✓ ) OWNED', $c15);
        $this->assertStringNotContainsString('( ✓ ) MORTGAGED', $c15);
        $this->assertStringNotContainsString('( ✓ ) RENTED', $c15);
    }

    public function test_a_historical_report_without_the_new_keys_still_renders(): void
    {
        [$ci, $folder] = $this->folder();
        // A report saved before these keys existed: no parents_house_status, no
        // other_residence_status, and a free-text OTHER RESIDENCES value that must survive.
        $report = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete,
            'personal_snapshot' => [
                'name' => 'LEGACY CLIENT', 'residence_status' => 'Living with Parents',
                'other_residences' => 'Legacy free text residence', 'civil_status' => 'Married',
                'material_cost_level' => 'Medium', 'reputation' => 'Good', 'barangay_findings' => 'None',
                'court_background_status' => 'No Legal Cases', 'lifestyle' => 'Modest',
            ],
        ]);
        $this->actingAs($ci);

        $this->assertArrayNotHasKey('parents_house_status', $report->personal_snapshot);

        // Nothing throws, nothing is invented, and the historical text is untouched.
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $rows = collect(collect($document['sections'])->firstWhere('title', 'I. Validated Personal Information')['rows'])
            ->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('N/A', $rows["Parents' House Status"]);
        $this->assertSame('Legacy free text residence', $rows['OTHER RESIDENCES (OWNED/MORTGAGED)']);
        $this->assertStringContainsString('Legacy free text residence', (string) $this->workbook($folder)->getCell('R15')->getValue());

        // Merely opening the form must not rewrite the historical record.
        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $this->assertArrayNotHasKey('parents_house_status', $report->fresh()->personal_snapshot);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folder(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SAVED CLIENT'])];
    }

    private function save(User $ci, ClientFolder $folder, ?int $coMakerId, array $snapshot, ?int $revision = null): void
    {
        $payload = $this->payload($snapshot);
        $payload['co_maker_id'] = $coMakerId;
        if ($revision !== null) {
            $payload['expected_revision'] = $revision;
        }
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();
    }

    private function workbook(ClientFolder $folder)
    {
        $temporary = tempnam(sys_get_temp_dir(), 'cibi-residence-xlsx-');
        file_put_contents($temporary, app(CibiExcelExporter::class)->generate($folder->fresh()));
        $sheet = IOFactory::load($temporary)->getSheetByName('CI REPORT - CIBI');
        unlink($temporary);

        return $sheet;
    }

    private function officialPdfHtml(ClientFolder $folder): string
    {
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);

        return view('reports.official.document', [
            'document' => $document,
            'pdfMode' => true,
            'clientFolder' => $folder,
            'type' => OfficialReportType::Cibi,
            'source' => null,
            'personParams' => [],
        ])->render();
    }

    private function payload(array $snapshot = []): array
    {
        return [
            'intent' => 'complete', 'start_date' => '2026-07-03', 'submitted_date' => '2026-07-04', 'party_type' => 'borrower',
            'branch_name' => 'BLU TIN-AO', 'account_officer_name' => 'IRISH JANE ALBOR', 'amount_applied' => '340000.00',
            'ci_risk_level' => 'mid', 'purpose_codes' => ['working_capital'], 'purpose_other' => null,
            'summary_totals' => ['institutions_checked' => 1, 'institutions_declared' => 1, 'loan_records_found' => 1],
            'personal_snapshot' => $snapshot + [
                'name' => 'VALIDATED CLIENT NAME', 'age' => 42, 'spouse_name' => 'VALIDATED SPOUSE', 'spouse_age' => 40,
                'present_address' => 'Validated present address', 'length_of_stay_months' => 36, 'residence_status' => 'Owned',
                'residence_status_from' => null, 'monthly_rent' => null, 'living_with_parents' => false,
                'other_residences' => 'One other residence', 'home_condition' => 'New', 'number_of_storeys' => 2,
                'material_cost_level' => 'Medium', 'living_condition' => 'Good', 'previous_address' => 'Validated previous address',
                'previous_address_length_of_stay_months' => 24, 'parents_address' => 'Validated parents address',
                'dependents_count' => 2, 'civil_status' => 'Married', 'separated_year' => null, 'reputation' => 'Good',
                'barangay_findings' => 'Validated barangay findings', 'court_background_status' => 'No Legal Cases',
                'court_background' => 'No adverse court record', 'lifestyle' => 'Modest', 'vehicles_owned' => 'One vehicle',
                'contact_details' => '09170000000 / client@example.test', 'other_remarks' => 'Validated personal remarks',
            ],
            'purpose_remarks' => 'Confidential narrative about the purpose.', 'negative_credit_findings' => 'Confidential narrative findings.',
            'other_remarks' => 'Validated facts.', 'prepared_by_name' => 'Assigned Investigator', 'noted_by_name' => 'CA1',
            'bank_accounts' => [], 'loan_records' => [], 'credit_checks' => [], 'income_summaries' => [], 'legal_findings' => [],
        ];
    }
}
