<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authentication\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateInitialAdministrator extends Command
{
    protected $signature = 'cims:create-admin';

    protected $description = 'Interactively provision the initial BRBI CIMS Administrator';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command is interactive only. Run it without --no-interaction.');

            return self::FAILURE;
        }

        if (User::where('role', UserRole::Administrator->value)->exists()) {
            $this->error('An Administrator account already exists. Initial provisioning was not performed.');

            return self::FAILURE;
        }

        $fullName = trim((string) $this->ask('Full name'));
        $employeeId = trim((string) $this->ask('Employee ID (optional)'));
        $username = Str::lower(trim((string) $this->ask('Username')));
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $data = [
            'full_name' => $fullName,
            'employee_id' => $employeeId === '' ? null : $employeeId,
            'username' => $username,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ];

        $validator = Validator::make($data, [
            'full_name' => ['required', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:50', 'unique:users,employee_id'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ]);

        if ($validator->fails()) {
            $this->error('Administrator account was not created:');

            foreach ($validator->errors()->all() as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $administrator = DB::transaction(function () use ($data): User {
            $administrator = User::create([
                'full_name' => $data['full_name'],
                'employee_id' => $data['employee_id'],
                'username' => $data['username'],
                'password' => $data['password'],
                'role' => UserRole::Administrator,
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $administrator->id,
                'action' => 'administrator.bootstrapped',
                'module' => 'authentication',
                'description' => 'The initial Administrator account was provisioned interactively.',
                'metadata' => [
                    'administrator_user_id' => $administrator->id,
                    'username' => $administrator->username,
                    'employee_id' => $administrator->employee_id,
                    'source' => 'cli',
                ],
                'user_agent' => 'artisan-cli',
            ]);

            return $administrator;
        });

        $this->info("Administrator '{$administrator->username}' created successfully.");

        return self::SUCCESS;
    }
}
