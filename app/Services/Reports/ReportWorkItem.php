<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * One row of the global Reports workspace, already carrying everything the table and the mobile
 * card render — the query hands these over fully populated, so the page performs no per-row lookup.
 *
 * Every action it exposes points at an existing route in the module's own workflow, with the exact
 * context attached: the Client Folder, the exact person (Applicant = no co_maker_id, a Co-Maker =
 * that one id) and, once a legitimate business exists, the exact income_source_id. The two
 * zero-business work-queue placeholders remain virtual and unbound until the module's existing
 * first-entry workflow establishes that legitimate business. A Pending row never advertises a
 * preview of a report that is not finished.
 */
final class ReportWorkItem
{
    public function __construct(
        public readonly string $kind,
        public readonly ?int $sourceId,
        public readonly int $clientFolderId,
        public readonly ?int $coMakerId,
        public readonly ?int $incomeSourceId,
        public readonly bool $isCompleted,
        public readonly ?CarbonImmutable $lastUpdatedAt,
        public readonly string $clientName,
        public readonly ?string $personName,
        public readonly ?string $businessName,
        /**
         * CI / BI only: the exact person's own Residence Check already supplies at least one value
         * the still-unsaved CI / BI form will runtime-prefill. Derived per render from current data
         * — never stored, and it creates nothing. See ReportWorkspaceQuery::residencePrefilledKeys().
         */
        public readonly bool $hasResidencePrefill = false,
    ) {}

    public static function fromRow(object $row, bool $hasResidencePrefill = false): self
    {
        return new self(
            kind: (string) $row->kind,
            sourceId: $row->source_id === null ? null : (int) $row->source_id,
            clientFolderId: (int) $row->client_folder_id,
            coMakerId: $row->co_maker_id === null ? null : (int) $row->co_maker_id,
            incomeSourceId: $row->income_source_id === null ? null : (int) $row->income_source_id,
            isCompleted: (int) $row->status_rank === 1,
            lastUpdatedAt: blank($row->sort_date) ? null : CarbonImmutable::parse($row->sort_date),
            clientName: (string) $row->client_name,
            personName: $row->person_name === null ? null : (string) $row->person_name,
            businessName: $row->business_name === null ? null : (string) $row->business_name,
            hasResidencePrefill: $hasResidencePrefill,
        );
    }

    public function typeLabel(): string
    {
        return ReportWorkspaceQuery::KINDS[$this->kind] ?? $this->kind;
    }

    /** Report types are told apart by icon, never by colour — colour is reserved for status. */
    public function typeIcon(): string
    {
        return match ($this->kind) {
            'cibi' => 'report',
            'business_report' => 'building',
            'residence_check' => 'home',
            default => 'media',
        };
    }

    public function statusLabel(): string
    {
        return $this->isCompleted ? 'Completed' : 'Pending';
    }

    public function personLabel(): string
    {
        return $this->coMakerId === null ? 'Applicant' : 'Co-Maker';
    }

    public function isUnboundBusiness(): bool
    {
        return in_array($this->kind, ['business_report', 'business_check'], true)
            && $this->incomeSourceId === null;
    }

    /** Stable per-row key: a derived not-started row has no source id of its own. */
    public function key(): string
    {
        return implode('-', [$this->kind, $this->clientFolderId, $this->coMakerId ?? 'applicant', $this->incomeSourceId ?? 'none', $this->sourceId ?? 'new']);
    }

    /** The exact person context every link and form carries forward. */
    public function personParams(): array
    {
        return $this->coMakerId === null ? [] : ['person' => 'co-maker', 'co_maker_id' => $this->coMakerId];
    }

    public function folderUrl(): string
    {
        return route('client-folders.show', [$this->clientFolderId] + $this->personParams());
    }

    /**
     * Nothing saved yet is a Create; an existing but unfinished record is a Continue.
     *
     * CI / BI is the one deliberate exception: its encoding page is a single save-once form rather
     * than a workflow the CI works through in stages, so an unfinished cibi_reports row is not
     * something they "continue" — every Pending CI / BI row reads Create Report. This is presentation
     * only: the row still comes from the same persisted-record query, the draft row is left exactly
     * as it is, and continueUrl() still opens that same exact-person CI / BI form.
     */
    public function continueLabel(): string
    {
        if ($this->kind === 'cibi') {
            return 'Create Report';
        }

        return $this->sourceId === null ? 'Create Report' : 'Continue Report';
    }

    /**
     * Is there already something to carry on from — a saved record, or (CI / BI only) a Residence
     * Check the form will prefill from? Presentation state only: nothing is written to make this
     * true, and it never changes the label.
     */
    public function hasPartialData(): bool
    {
        return $this->sourceId !== null || $this->hasResidencePrefill;
    }

    /**
     * A plus for work with nothing behind it yet, a pencil for work already part-way there.
     *
     * Every Pending CI / BI row reads Create Report (see continueLabel()), so for CI / BI the icon
     * is the only thing that distinguishes a blank start from one the CI can immediately continue
     * encoding — including the case where nothing is saved yet but the exact person's Residence
     * Check already supplies the runtime prefill. Every other kind keeps the label-matching rule.
     */
    public function continueIcon(): string
    {
        if ($this->kind === 'cibi') {
            return $this->hasPartialData() ? 'edit' : 'plus';
        }

        return $this->continueLabel() === 'Create Report' ? 'plus' : 'edit';
    }

    /**
     * Opens the module's own edit workflow at the exact context — never a generic page the user
     * would have to search from again. Creation still only happens inside that workflow.
     */
    public function continueUrl(): string
    {
        return match ($this->kind) {
            'cibi' => route('client-folders.cibi-report.edit', [$this->clientFolderId] + $this->personParams()),
            'business_report' => $this->isUnboundBusiness()
                ? route('client-folders.income-sources.index', [$this->clientFolderId] + $this->personParams())
                : route('client-folders.income-sources.edit', [$this->clientFolderId, $this->incomeSourceId] + $this->personParams()),
            'residence_check' => route('client-folders.residence-checks.create', [$this->clientFolderId] + $this->personParams()),
            'business_check' => route('client-folders.business-checks.create', [$this->clientFolderId]
                + ($this->incomeSourceId === null ? [] : ['income_source_id' => $this->incomeSourceId])
                + $this->personParams()),
        };
    }

    /**
     * Preview is only ever offered on a Completed row. The two encoded reports preview through the
     * existing per-folder preview route; the two photo checks preview through the existing batch
     * print endpoint, addressed by their own exact check id.
     *
     * @return array{label: string, url: string, method: string, fields: array<string, int|string>}|null
     */
    public function previewAction(): ?array
    {
        if (! $this->isCompleted) {
            return null;
        }

        return match ($this->kind) {
            'cibi' => $this->get(route('client-folders.generated-reports.preview', [
                $this->clientFolderId, 'report_type' => 'cibi',
            ] + $this->personParams())),
            'business_report' => $this->get(route('client-folders.generated-reports.preview', [
                $this->clientFolderId, 'report_type' => 'business_income_source', 'income_source_id' => $this->incomeSourceId,
            ] + $this->personParams())),
            default => $this->checkAction('Preview Report', 'client-folders.residence-business-checks.batch-print'),
        };
    }

    /**
     * Only the formats the existing routes really produce for this exact row — the Download menu is
     * built from this list, so it can never offer a format the backend cannot deliver. The two
     * encoded reports export PDF and Excel; the two photo checks export PDF and Word. The versioned
     * GeneratedReport history stays in the Client Folder, where it already lives.
     *
     * @return list<array{format: string, label: string, icon: string, url: string, method: string, fields: array<string, int|string>}>
     */
    public function downloadActions(): array
    {
        if (! $this->isCompleted) {
            return [];
        }

        $person = array_filter(['co_maker_id' => $this->coMakerId], fn ($value) => $value !== null);

        return match ($this->kind) {
            'cibi' => [
                $this->download('PDF', 'file-pdf', route('client-folders.cibi-report.export-pdf', $this->clientFolderId), $person),
                $this->download('Excel', 'spreadsheet', route('client-folders.cibi-report.export-excel', $this->clientFolderId), $person),
            ],
            'business_report' => [
                $this->download('PDF', 'file-pdf', route('client-folders.income-sources.export-pdf', [
                    $this->clientFolderId, $this->incomeSourceId,
                ] + $this->personParams()), method: 'GET'),
                $this->download('Excel', 'spreadsheet', route('client-folders.income-sources.export-excel', [
                    $this->clientFolderId, $this->incomeSourceId,
                ] + $this->personParams()), $person),
            ],
            default => [
                $this->checkDownload('PDF', 'file-pdf', 'client-folders.residence-business-checks.batch-export-pdf'),
                $this->checkDownload('Word', 'file-word', 'client-folders.residence-business-checks.batch-export-docx'),
            ],
        };
    }

    /**
     * @param  array<string, int|string>  $fields
     * @return array{format: string, label: string, icon: string, url: string, method: string, fields: array<string, int|string>}
     */
    private function download(string $format, string $icon, string $url, array $fields = [], string $method = 'POST'): array
    {
        return ['format' => $format, 'label' => 'Download '.$format, 'icon' => $icon, 'url' => $url, 'method' => $method, 'fields' => $fields];
    }

    /** @return array{format: string, label: string, icon: string, url: string, method: string, fields: array<string, int|string>} */
    private function checkDownload(string $format, string $icon, string $routeName): array
    {
        return $this->download($format, $icon, route($routeName, $this->clientFolderId), $this->checkFields());
    }

    /** @return array{label: string, url: string, method: string, fields: array<string, int|string>} */
    private function checkAction(string $label, string $routeName): array
    {
        return [
            'label' => $label,
            'url' => route($routeName, $this->clientFolderId),
            'method' => 'POST',
            'fields' => $this->checkFields(),
        ];
    }

    /**
     * This one check, addressed by its own exact id under the exact person — the batch endpoint
     * drops any id that does not belong to that person, so one row can never reach another's.
     *
     * @return array<string, int>
     */
    private function checkFields(): array
    {
        $idField = $this->kind === 'residence_check' ? 'residence_check_ids[]' : 'business_check_ids[]';

        return array_filter([
            $idField => $this->sourceId,
            'co_maker_id' => $this->coMakerId,
        ], fn ($value) => $value !== null);
    }

    /** @return array{label: string, url: string, method: string, fields: array<string, int|string>} */
    private function get(string $url): array
    {
        return ['label' => 'Preview Report', 'url' => $url, 'method' => 'GET', 'fields' => []];
    }
}
