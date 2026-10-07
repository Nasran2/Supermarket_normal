<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->index(['active', 'name'], 'products_active_name_index');
            if (DB::getDriverName() === 'mysql') {
                $t->fullText('name', 'products_name_fulltext');
            }
        });
        Schema::table('sales', fn (Blueprint $t) => $t->index(['status', 'sold_at'], 'sales_status_date_index'));
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('sales_status_date_index'));
        Schema::table('products', function (Blueprint $t) {
            if (DB::getDriverName() === 'mysql') {
                $t->dropFullText('products_name_fulltext');
            }$t->dropIndex('products_active_name_index');
        });
    }
};
