<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Out-of-band local provisioning; never commits credentials. */
class BootstrapAdmin extends Command
{
    protected $signature = 'dcms:bootstrap-admin {email} {--name=Administrator}';

    protected $description = 'Provision the first local Nexora Admin with a prompted password';

    public function handle(AuditWriter $audit): int
    {
        if (! app()->environment('local')) {
            $this->error('Use an approved invitation and secret provisioning process outside local development.');

            return self::FAILURE;
        }
        if (User::query()->exists()) {
            $this->error('Bootstrap is available only before the first account.');

            return self::FAILURE;
        }
        $data = ['email' => mb_strtolower(trim($this->argument('email'))), 'name' => $this->option('name'), 'password' => $this->secret('Choose a password (12+ characters, mixed case, number and symbol)')];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'max:254'], 'name' => ['required', 'string', 'max:160'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $audit): void {
            $user = new User($data);
            $user->status = AccountStatus::Active;
            $user->email_verified_at = now();
            $user->save();
            $user->assignRole('System Admin');
            $user->givePermissionTo(['roles.manage', 'users.invite']);
            $audit->record($user, 'bootstrap_admin', metadata: ['role' => 'System Admin'], reason: 'Initial local development account');
        });
        $this->info('Admin provisioned.');

        return self::SUCCESS;
    }
}
