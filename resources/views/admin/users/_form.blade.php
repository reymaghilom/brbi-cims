@php($roleOptions = collect($roles)->mapWithKeys(fn ($role) => [$role->value => str($role->value)->replace('_', ' ')->title()->toString()])->all())
@php($currentPhotoUrl = isset($managedUser) ? $managedUser->profilePhotoUrl() : null)

<x-form.input name="full_name" label="Full name" :value="$managedUser->full_name ?? null" required class="sm:col-span-2" autocomplete="name" />
<x-form.input name="employee_id" label="Employee ID" :value="$managedUser->employee_id ?? null" help="Optional internal employee reference." />
<x-form.input name="username" label="Username" :value="$managedUser->username ?? null" required autocomplete="off" />
<x-form.select name="role" label="Role" :options="$roleOptions" :selected="isset($managedUser) ? $managedUser->role->value : null" required class="sm:col-span-2" help="Role changes invalidate the user's existing sessions." />

<div class="sm:col-span-2">
    <label for="profile-photo" class="ui-label">Profile Photo</label>
    <div class="flex items-center gap-3">
        <span class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-full border border-ui-border bg-surface-muted text-text-muted" data-photo-preview-wrap>
            <img src="{{ $currentPhotoUrl }}" alt="" class="size-full object-cover" @class(['hidden' => ! $currentPhotoUrl]) data-photo-preview>
            <x-ui.icon name="user" size="size-6" @class(['hidden' => $currentPhotoUrl]) data-photo-preview-placeholder />
        </span>
        <input id="profile-photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" class="ui-control file:mr-3 file:rounded-control file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:font-semibold file:text-brand-primary" @if($errors->has('profile_photo')) aria-invalid="true" aria-describedby="profile-photo-error" @else aria-describedby="profile-photo-help" @endif data-photo-input>
    </div>
    <p id="profile-photo-help" class="ui-help">JPG, JPEG, PNG, or WEBP. Max 2 MB.@if($currentPhotoUrl) Leave blank to keep the current photo.@endif</p>
    <x-form.validation-message for="profile_photo" />
</div>
