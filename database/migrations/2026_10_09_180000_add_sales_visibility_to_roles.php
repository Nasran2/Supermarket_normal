<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Preserve existing access; new roles choose their scope in the role editor.
            $table->string('sales_visibility', 10)->default('ALL');
        });
    }

    public function down(): void
    {
        if (DB::table('roles')->where('sales_visibility', '!=', 'ALL')->exists()) {
            throw new RuntimeException('Restricted roles exist; removing sales visibility would widen their access.');
        }
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('sales_visibility'));
    }
};
