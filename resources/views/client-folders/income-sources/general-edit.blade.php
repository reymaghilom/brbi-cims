@extends('layouts.app')

@section('title', 'Sources of Income Declared by Client')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Income Sources', 'url' => route('client-folders.income-sources.manage', $clientFolder)], ['label' => $incomeSource->source_name]]" />
    <x-ui.page-header title="SOURCES OF INCOME DECLARED BY CLIENT" eyebrow="Official BRBI fallback form"><x-slot:description>To be used as guide for credit profiling, credit investigation, and credit evaluation. This remains a valid official fallback report.</x-slot:description><x-slot:actions><x-ui.status-badge :status="$incomeSource->state" /><a target="_blank" rel="noopener" href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'general_income_source', 'income_source_id' => $incomeSource->id]) }}" class="ui-button-secondary">Preview / Print</a><a href="{{ route('client-folders.generated-reports.index', $clientFolder) }}" class="ui-button-secondary">Report Outputs</a><a href="{{ route('client-folders.income-sources.manage', $clientFolder) }}" class="ui-button-secondary">Back to Sources</a></x-slot:actions></x-ui.page-header>
    @if($errors->any())<div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert"><strong>Please correct the highlighted fields.</strong> No changes were saved.</div>@endif
    <form method="POST" action="{{ route('client-folders.income-sources.general.update', [$clientFolder, $incomeSource]) }}" class="space-y-6" data-unsaved-form>
        @csrf @method('PUT')
        <x-ui.form-section title="Report Header" description="Account Officer is report data only and does not affect folder ownership.">
            <x-form.input name="source_name" label="Record Name" :value="$incomeSource->source_name" required />
            <x-form.input name="applicant_name_snapshot" label="Applicant Name" :value="$incomeSource->applicant_name_snapshot" />
            <x-form.input name="branch_name" label="Branch" :value="$incomeSource->branch_name" />
            <x-form.input name="amount_applied" label="Amount Applied" type="number" step="0.01" min="0" :value="$incomeSource->amount_applied" />
            <x-form.input name="account_officer_name" label="Account Officer" :value="$incomeSource->account_officer_name" />
            <div><span class="ui-label">Template</span><p class="ui-control bg-surface-subtle">{{ $incomeSource->template->name }} · Version {{ $incomeSource->template_version }}</p><p class="ui-help">Locked when this source was created.</p></div>
        </x-ui.form-section>
        @include('client-folders.cibi-report._repeater', ['section' => 'items', 'title' => 'Declared Income Sources', 'description' => 'Rank every source by contribution; 1 is the highest contributor.', 'records' => $incomeSource->generalReport->declaredItems, 'fields' => [
            ['name' => 'source_name', 'label' => 'Source Name'], ['name' => 'source_type', 'label' => 'Source Type'], ['name' => 'amount_contribution', 'label' => 'Contribution Amount', 'type' => 'number', 'step' => '0.01'], ['name' => 'contribution_rank', 'label' => 'Contribution Rank', 'type' => 'number'], ['name' => 'description', 'label' => 'Details', 'type' => 'textarea', 'wide' => true], ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'wide' => true],
        ]])
        <x-ui.form-section title="General Remarks"><x-form.textarea name="general_remarks" label="General Remarks / Details" :value="$incomeSource->generalReport->general_remarks" class="sm:col-span-2" rows="6" /></x-ui.form-section>
        <x-ui.sticky-form-toolbar><span>Revision {{ $incomeSource->revision }} · Official outputs use the latest saved data.</span><x-slot:actions><button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return</button><button type="submit" name="intent" value="stay" class="ui-button-secondary">Save Draft</button><button type="submit" name="intent" value="complete" class="ui-button-primary">Save and Mark Complete</button></x-slot:actions></x-ui.sticky-form-toolbar>
    </form>
@endsection
