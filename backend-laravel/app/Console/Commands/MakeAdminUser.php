<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeAdminUser extends Command
{
    protected $signature = 'nasaq:make-admin {email : The email address of the user to promote or create}';

    protected $description = 'Promote an existing user to administrator or create a new administrator account';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'role' => 'admin',
                'account_status' => 'active',
            ]);
            $this->info("User [{$email}] promoted to admin successfully.");

            return Command::SUCCESS;
        }

        $name = $this->ask('Enter admin name', 'System Administrator');
        $password = $this->secret('Enter admin password');

        if (empty($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters long.');

            return Command::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'account_status' => 'active',
            'preferred_locale' => 'en',
        ]);

        $this->info("New admin user [{$email}] created successfully.");

        return Command::SUCCESS;
    }
}
