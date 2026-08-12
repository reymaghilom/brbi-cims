@props(['title' => 'Unable to load', 'message' => 'Please try again.', 'action' => null])

<section {{ $attributes->class('rounded-card border border-danger/25 bg-danger-soft p-5 text-danger') }} role="alert">
    <div class="flex items-start gap-3"><x-ui.icon name="warning" /><div><h3 class="font-bold">{{ $title }}</h3><p class="mt-1 text-sm">{{ $message }}</p></div></div>
    @if($action)<form method="POST" action="{{ $action }}" class="mt-4">@csrf<button class="ui-button-secondary">Retry</button></form>@endif
</section>
