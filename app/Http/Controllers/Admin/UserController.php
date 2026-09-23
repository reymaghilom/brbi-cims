<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateManagedUser;
use App\Actions\Users\UpdateManagedUser;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
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
            'roles' => UserRole::cases(),
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

        return redirect()->route('admin.users.index')
            ->with('status', 'User created. They must change the temporary password after signing in.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.edit', [
            'managedUser' => $user,
            'roles' => UserRole::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateManagedUser $updateUser): RedirectResponse|JsonResponse
    {
        try {
            $changed = $updateUser->execute($request->user(), $user, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        if (! $changed) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No changes were made.', 'no_change' => true]);
            }

            return back()->with('user_modal_notice', 'No changes were made.')->withInput([
                'user_modal' => 'edit',
                'user_modal_id' => $user->id,
            ]);
        }

        if ($request->expectsJson()) {
            $request->session()->flash('status', 'User information updated.');

            return response()->json([
                'message' => 'User information updated.',
                'no_change' => false,
                'redirect_url' => route('admin.users.index'),
            ]);
        }

        return back()->with('status', 'User information updated.');
    }
}
