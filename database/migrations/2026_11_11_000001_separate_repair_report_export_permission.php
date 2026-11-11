<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'repairs.reports.export',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $view = DB::table('permissions')->where('name', 'repairs.reports')->value('id');
        $export = DB::table('permissions')->where('name', 'repairs.reports.export')->value('id');

        DB::table('permission_role')->insertOrIgnoreUsing(
            ['role_id', 'permission_id'],
            DB::table('permission_role')->where('permission_id', $view)->select('role_id')->selectRaw('? AS permission_id', [$export]),
        );
    }

    public function down(): void
    {
        $export = DB::table('permissions')->where('name', 'repairs.reports.export')->value('id');
        DB::table('permission_role')->where('permission_id', $export)->delete();
        DB::table('permissions')->where('id', $export)->delete();
    }
};
