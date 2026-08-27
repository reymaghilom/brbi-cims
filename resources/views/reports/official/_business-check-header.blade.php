{{-- Compact Business Check header — same 2-column .residence-header table Residence Check's own
     header already uses (see the Residence branch above), just fed Business values: Applicant/
     Co-Maker Name + Date on one row, Location + CI on the next, Subject and (if present) Remarks
     below that. Matches business1.docx/business1.pdf, the visual reference for this layout: no
     large report title, no "Business DOCUMENTATION" subtitle. --}}
<table class="residence-header"><tr>
    <td class="residence-header-left">
        <p><strong>{{ $photoSection['party_label'] ?? 'Applicant Name' }}:</strong> {{ $photoSection['subject'] }}</p>
        <p><strong>Location:</strong> {{ $photoSection['location'] ?: '—' }}</p>
        <p><strong>Subject:</strong> {{ $photoSection['heading'] }}{{ filled($photoSection['business_name'] ?? null) ? ' ('.$photoSection['business_name'].')' : '' }}</p>
        @if(filled($photoSection['remarks']))<p><strong>Remarks:</strong> {{ $photoSection['remarks'] }}</p>@endif
    </td>
    <td class="residence-header-right">
        <p><strong>Date:</strong> {{ $photoSection['ci_date'] ?? '—' }}</p>
        <p><strong>CI:</strong> {{ $photoSection['ci'] ?: '—' }}</p>
    </td>
</tr></table>
