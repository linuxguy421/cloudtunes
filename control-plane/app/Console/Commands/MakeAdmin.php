<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'cloudtunes:make-admin {email : Email address of an existing registered user}';

    protected $description = 'Promote an existing CloudTunes user to administrator.';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No user with that email exists. Register the account first.');

            return self::FAILURE;
        }

        $user->forceFill([
            'role' => 'admin',
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => null,
        ])->save();

        $this->info("{$user->email} is now an administrator.");

        return self::SUCCESS;
    }
}
