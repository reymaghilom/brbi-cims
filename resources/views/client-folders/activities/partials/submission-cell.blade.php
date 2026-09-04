@props(['activity', 'clientFolder', 'attachmentCount', 'singleAttachment', 'singleAttachmentIsPreviewable'])
<div class="min-w-36 space-y-2 text-xs">
    @if($attachmentCount === 1 && $singleAttachmentIsPreviewable)
        <button type="button" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="ci-proof-preview-{{ $activity->id }}-{{ $singleAttachment->id }}" aria-label="View {{ $singleAttachment->file_name }}"><x-ui.icon name="attachment" size="size-4" />1 Attachment</button>
    @elseif($attachmentCount === 1)
        <a href="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $singleAttachment]) }}" target="_blank" rel="noopener" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" aria-label="View {{ $singleAttachment->file_name }}"><x-ui.icon name="attachment" size="size-4" />1 Attachment</a>
    @elseif($attachmentCount > 1)
        <button type="button" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="ci-proof-list-{{ $activity->id }}" aria-label="View {{ $attachmentCount }} attachments for {{ $activity->name }}"><x-ui.icon name="attachment" size="size-4" />{{ $attachmentCount }} Attachments</button>
    @else
        <span class="flex items-center gap-1.5 font-semibold text-text-muted"><x-ui.icon name="attachment" size="size-4" />No Attachment</span>
    @endif
    @if($activity->submitted_at)
        <div class="flex flex-col items-start gap-1">
            <div class="space-y-1 leading-4">
                <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-bold text-success"><x-ui.icon name="check-circle" size="size-3.5" />Submitted</span>
                <span class="block text-text-muted">{{ $activity->submitted_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</span>
                @if($activity->submitted_to)<span class="block max-w-44 truncate text-text-muted" title="{{ $activity->submitted_to }}">To: {{ $activity->submitted_to }}</span>@endif
            </div>
            @if($activity->status === App\Enums\ActivityStatus::Completed)
                <button type="button" class="rounded-control text-left text-[0.8125rem] font-semibold text-brand-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="submit-activity-{{ $activity->id }}" data-submission-action="update">View / Update</button>
            @endif
        </div>
    @else
        <div class="flex flex-col items-start gap-1">
            <span class="inline-flex items-center rounded-full border border-ui-border bg-surface-subtle px-2 py-0.5 font-bold text-text-muted">Not Submitted</span>
            <button type="button" class="rounded-control text-left text-[0.8125rem] font-semibold text-brand-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="submit-activity-{{ $activity->id }}" data-submission-action="create" data-ci-submission-mark="{{ $activity->id }}" @if($activity->status !== App\Enums\ActivityStatus::Completed) hidden @endif>Mark as Submitted</button>
        </div>
    @endif
</div>
