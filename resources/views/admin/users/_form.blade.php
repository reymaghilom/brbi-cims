@php($roleOptions = collect($roles)->mapWithKeys(fn ($role) => [$role->value => str($role->value)->replace('_', ' ')->title()->toString()])->all())

<x-form.input name="full_name" label="Full name" :value="$managedUser->full_name ?? null" required class="sm:col-span-2" autocomplete="name" />
<x-form.input name="employee_id" label="Employee ID" :value="$managedUser->employee_id ?? null" help="Optional internal employee reference." />
<x-form.input name="username" label="Username" :value="$managedUser->username ?? null" required autocomplete="off" />
<x-form.select name="role" label="Role" :options="$roleOptions" :selected="isset($managedUser) ? $managedUser->role->value : null" required class="sm:col-span-2" help="Role changes invalidate the user's existing sessions." />
