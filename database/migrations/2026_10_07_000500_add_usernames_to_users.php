<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique();
        });

        // Preserve existing accounts and passwords, assigning distinct login names.
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $base = strtolower(explode('@', $user->email, 2)[0]);
                $base = trim(preg_replace('/[^a-z0-9._-]/', '', $base), '._-');
                $base = substr($base !== '' ? $base : 'user', 0, 48);
                $username = $base;
                $suffix = 1;
                while (DB::table('users')->where('username', $username)->exists()) {
                    $username = $base.'-'.$suffix++;
                }
                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
