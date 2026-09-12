@props(['required' => false])
{{-- Label suffix for a Schedule date input: "(optional)" normally, the standard required marker
     for Scheduled and For Follow-up. The shared schedule guard in app.js swaps them live. --}}
<span class="font-normal text-text-muted" data-schedule-date-optional @if($required) hidden @endif>(optional)</span><span data-schedule-date-required @unless($required) hidden @endunless><span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></span>
