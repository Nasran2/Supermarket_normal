<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pos:admin {email} {--name=Administrator}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists. Use Users to edit it.');

        return 1;
    }
    $password = $this->secret('New admin password (at least 10 characters)');
    if (strlen($password) < 10) {
        $this->error('Password is too short.');

        return 1;
    }
    $role = Role::where('name', 'Administrator')->firstOrFail();
    User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role_id' => $role->id, 'active' => true]);
    $this->info('Administrator created.');
})->purpose('Create an administrator without a default password');
