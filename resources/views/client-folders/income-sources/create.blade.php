@extends('layouts.app')

@section('title', 'Add Income Source')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Business / Income Sources', 'url' => route('client-folders.income-sources.manage', $clientFolder)], ['label' => 'Add']]" />
    <x-ui.page-header title="Add Income Source" eyebrow="Select the official form"><x-slot:description>Choose a dedicated compatible report when available. Otherwise, the official general income-source form is a valid fallback.</x-slot:description></x-ui.page-header>

    <form method="POST" action="{{ route('client-folders.income-sources.store', $clientFolder) }}" class="space-y-6">
        @csrf
        <x-ui.form-section title="Source Identity" description="This creates one independent income-source record. The selected template is locked after creation.">
            <x-form.input name="source_name" label="Income Source Name" :value="null" required />
            <x-form.input name="business_name" label="Business Name (if applicable)" :value="null" />
        </x-ui.form-section>
        <section class="ui-panel p-5 sm:p-7" aria-labelledby="template-title">
            <h2 id="template-title" class="ui-section-title">Official Template</h2><p class="mt-1 text-sm text-text-muted">Only active templates are selectable.</p>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @forelse($templates as $template)
                    <label class="cursor-pointer rounded-card border border-ui-border p-4 transition has-[:checked]:border-brand-primary has-[:checked]:bg-brand-soft">
                        <span class="flex items-start gap-3"><input type="radio" name="income_source_template_id" value="{{ $template->id }}" @checked((string) old('income_source_template_id') === (string) $template->id) class="mt-1 size-4 border-ui-border-strong text-brand-primary focus:ring-brand-primary"><span><span class="block font-bold">{{ $template->name }}</span><span class="mt-1 block text-sm text-text-muted">{{ $template->description ?: ($template->is_fallback ? 'Official fallback for sources without a compatible dedicated template.' : 'Dedicated BRBI business validation form.') }}</span>@if($template->is_fallback)<span class="mt-2 inline-flex rounded-full bg-progress-soft px-2 py-1 text-xs font-bold text-progress">Official fallback</span>@endif</span></span>
                    </label>
                @empty
                    <x-ui.empty-state title="No active templates" description="An Administrator must activate at least one official income-source template." icon="report" />
                @endforelse
            </div>
            <x-form.validation-message for="income_source_template_id" />
        </section>
        <x-ui.sticky-form-toolbar><span>The selected template cannot be changed after creation.</span><x-slot:actions><a href="{{ route('client-folders.income-sources.manage', $clientFolder) }}" class="ui-button-secondary">Cancel</a><button class="ui-button-primary" @disabled($templates->isEmpty())>Create and Open Form</button></x-slot:actions></x-ui.sticky-form-toolbar>
    </form>
@endsection
