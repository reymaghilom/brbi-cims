<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateManagedUser;
use App\Actions\Users\UpdateManagedUser;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()->orderBy('full_name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', ['roles' => UserRole::cases()]);
    }

    public function store(StoreUserRequest $request, CreateManagedUser $createUser): RedirectResponse
    {
        $user = $createUser->execute($request->user(), $request->validated());

        return redirect()->route('admin.users.edit', $user)
            ->with('status', 'User created. They must change the temporary password after signing in.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.edit', [
            'managedUser' => $user,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateManagedUser $updateUser): RedirectResponse
    {
        try {
            $updateUser->execute($request->user(), $user, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        return back()->with('status', 'User information updated.');
    }
}
