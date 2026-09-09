@extends('layouts.app')

@section('title', 'Residence & Business Report')

@section('content')
    @php
        // Only the breadcrumb and the check-saved marker below still need this here — the listing's
        // own person/label/sort-column locals moved into partials/checks-listing.blade.php with it,
        // so that partial renders identically whether it is included here or returned on its own as
        // the async fragment.
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
    @endphp
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Residence & Business Report']]" />

    {{-- No page-specific status banner here — layouts.app's own <main>-level toast (session('status')
         via <x-ui.toast>, auto-dismissing) already covers every save/update/delete redirect that
         lands on this page. A second, persistent (never auto-dismissing) banner used to render here
         too, duplicating that same message and never going away on its own. --}}
    @if(session('status') && session('statusType', 'success') === 'success')
        {{-- Business Check's Save/Update is a plain form POST + server redirect landing right back
             here — unlike Residence Check's own XHR + brbi:check-saved postMessage, which never
             navigates its iframe at all. Without this marker, a successful Business Check save
             left the check-report-dialog's iframe simply displaying this whole page nested inside
             itself: the save worked, but from the user's side nothing closed and the modal looked
             broken/stuck. The message/status-type are carried straight through the postMessage
             itself (app.js stashes them in sessionStorage for the parent's own reload to pick up) —
             deliberately not re-flashed via session()->keep() here, since that would leave this
             same status sitting around for the parent's reload to independently render a second
             time through its own generic session('status') toast (layouts.app), i.e. a duplicate. --}}
        <span hidden data-check-saved-notify
              data-check-saved-return-url="{{ route('client-folders.residence-business.edit', [$clientFolder] + $personParams) }}"
              data-check-saved-message="{{ session('status') }}"
              data-check-saved-status-type="{{ session('statusType', 'success') }}"></span>
    @endif

    @include('client-folders.residence-business.partials.checks-listing')

    {{-- stay-on-page: a confirmed save AUTO-UPDATEs [data-checks-listing] above from the same
         authoritative fragment instead of reloading this whole page around it (see app.js's
         brbi:check-saved handler and refreshChecksListing). A save that genuinely returns a
         DIFFERENT folder/person context still navigates there, exactly as before. --}}
    <x-ui.check-report-modal :stay-on-page="true" />

    <form id="check-batch-print-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-print', $clientFolder) }}" target="_blank" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
    <form id="check-batch-export-pdf-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-export-pdf', $clientFolder) }}" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
    <form id="check-batch-export-docx-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-export-docx', $clientFolder) }}" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
    <form id="check-batch-delete-form" method="POST" action="{{ route('client-folders.residence-business-checks.batch-delete', $clientFolder) }}" hidden>
        @csrf<input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
    </form>
@endsection
