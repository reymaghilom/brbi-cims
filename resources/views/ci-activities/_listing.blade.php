{{-- The CI Activities worklist itself. Rendered inside the page, and on its own as the
     response to a pagination click, so the shell around it is never re-rendered. --}}
        @if($rows->isEmpty())
            <x-ui.empty-state title="No CI activities found" description="Try adjusting your filters or create activities inside a Client Folder." icon="activity" />
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="ui-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-3 text-left">Client / Loan</th>
                            <th class="px-3 py-3 text-left">Person</th>
                            <th class="px-3 py-3 text-left">Activity</th>
                            <th class="px-3 py-3 text-left">Status</th>
                            <th class="px-3 py-3 text-left">Schedule</th>
                            <th class="px-3 py-3 text-left">Progress</th>
                            <th class="px-3 py-3 text-left">Updated</th>
                            <th class="px-3 py-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        @foreach($rows as $row)
                            <tr>
                                <td class="px-3 py-3">
                                    <a href="{{ route('client-folders.show', $row->clientFolder) }}" class="block font-bold text-brand-primary hover:underline">{{ strtoupper($row->clientFolder->display_name) }}</a>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="min-w-0">
                                        <span class="block text-xs font-semibold text-text-muted">{{ $row->personLabel }}</span>
                                        <span class="block truncate font-medium text-text-main">{{ $row->personName }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">@include('ci-activities.partials.activity-cell', ['row' => $row])</td>
                                <td class="px-3 py-3">@include('ci-activities.partials.status-badge', ['row' => $row])</td>
                                <td class="px-3 py-3">@include('ci-activities.partials.schedule-cell', ['row' => $row])</td>
                                <td class="px-3 py-3">@include('ci-activities.partials.progress-ring', ['numerator' => $row->progressNumerator, 'denominator' => $row->progressDenominator, 'percent' => $row->progressPercent])</td>
                                <td class="px-3 py-3">
                                    <span class="block font-semibold text-text-main">{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>
                                    <span class="block text-text-muted">{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('g:i A') }}</span>
                                </td>
                                <td class="px-3 py-3"><a href="{{ $row->openUrl }}" class="ui-button-secondary-compact"><x-ui.icon name="open" size="size-3.5" data-ci-action-icon />Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 lg:hidden">
                @foreach($rows as $row)
                    <div class="rounded-card border border-ui-border bg-surface p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('client-folders.show', $row->clientFolder) }}" class="block font-bold text-brand-primary hover:underline">{{ strtoupper($row->clientFolder->display_name) }}</a>
                                <span class="block text-xs font-semibold text-text-muted">{{ $row->personLabel }} &middot; {{ $row->personName }}</span>
                            </div>
                            @include('ci-activities.partials.status-badge', ['row' => $row])
                        </div>
                        <div class="mt-3 border-t border-ui-border pt-3">@include('ci-activities.partials.activity-cell', ['row' => $row])</div>
                        <div class="mt-3 grid grid-cols-2 gap-3 border-t border-ui-border pt-3 text-sm">
                            <div>
                                <p class="ui-label mb-1">Schedule</p>
                                @include('ci-activities.partials.schedule-cell', ['row' => $row])
                            </div>
                            <div>
                                <p class="ui-label mb-1">Progress</p>
                                @include('ci-activities.partials.progress-ring', ['numerator' => $row->progressNumerator, 'denominator' => $row->progressDenominator, 'percent' => $row->progressPercent])
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-ui-border pt-3">
                            <div class="text-xs text-text-muted">
                                <p class="font-semibold text-text-muted">Updated</p>
                                <p>{{ $row->updatedAt?->timezone(config('cims.display_timezone'))->format('M j, Y \\a\\t g:i A') }}</p>
                            </div>
                            <a href="{{ $row->openUrl }}" class="ui-button-primary-compact"><x-ui.icon name="open" size="size-3.5" data-ci-action-icon />Open Activity</a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-text-muted">Showing {{ $rows->firstItem() ?? 0 }} to {{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }} activities</p>
                <div class="flex items-center gap-3">
                    <x-ui.compact-pagination :paginator="$rows" aria-label="CI activities pagination" data-ci-activities-pagination />
                    <select name="per_page" form="global-ci-secondary-filter" class="ui-control min-h-9 w-28 py-1.5 text-sm" onchange="this.form.submit()">
                        @foreach($perPageOptions as $option)
                            <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }} / page</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif
