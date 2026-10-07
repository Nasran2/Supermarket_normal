<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

Artisan::command('pos:admin {email} {--name=Administrator} {--username= : Login username}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists. Use Users to edit it.');

        return 1;
    }
    $username = $this->option('username');
    if ($username === null) {
        $base = trim(preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email, 2)[0])), '._-');
        $base = substr($base !== '' ? $base : 'admin', 0, 48);
        $username = $base;
        $suffix = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.'-'.$suffix++;
        }
    }
    $username = strtolower(trim($username));
    $validator = Validator::make(['username' => $username], ['username' => 'required|string|max:64|regex:/^[a-z0-9][a-z0-9._-]*$/|unique:users,username']);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }
    $password = $this->secret('New admin password (at least 10 characters)');
    if (strlen($password) < 10) {
        $this->error('Password is too short.');

        return 1;
    }
    $role = Role::where('name', 'Administrator')->firstOrFail();
    User::create(['name' => $this->option('name'), 'username' => $username, 'email' => $email, 'password' => $password, 'role_id' => $role->id, 'active' => true]);
    $this->info('Administrator created. Username: '.$username);
})->purpose('Create an administrator without a default password');
