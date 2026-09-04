{{--
    Expected props: $activities (up to 50, newest first — see ClientFolderOverview::documentationActivity()),
    $category ('residence'|'business', used only to target this category's own View All modal).
    Reuses the exact CI Activities timeline partial (connector, dot, actor, date/time) so this
    panel shares one visual language with the rest of the app, and takes(5) off the same
    authoritative collection the "View All" modal renders in full — never a second query.
--}}
@php($recentActivities = $activities->take(5))
@if($recentActivities->isEmpty())
    <p class="mt-3 text-xs leading-5 text-text-muted">No recent activity yet.</p>
@else
    <ol class="relative mt-3 space-y-0">
        @foreach($recentActivities as $event)
            @include('client-folders.activities.partials.history-entry', ['event' => $event, 'showConnector' => ! $loop->last])
        @endforeach
    </ol>
    <div class="mt-3 border-t border-ui-border pt-3">
        <button type="button" class="w-full cursor-pointer text-center text-sm font-bold text-brand-primary hover:underline" data-modal-open="documentation-activity-history-{{ $category }}">View All</button>
    </div>
@endif
