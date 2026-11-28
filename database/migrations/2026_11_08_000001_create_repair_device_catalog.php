<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('repair_brands') || ! Schema::hasColumn('repair_brands', 'name_key')) {
            Schema::dropIfExists('repair_brands');
            Schema::create('repair_brands', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->string('name_key', 80)->unique();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('repair_models') || ! Schema::hasColumn('repair_models', 'name_key')) {
            Schema::dropIfExists('repair_models');
            Schema::create('repair_models', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->index();
                $table->string('name', 120);
                $table->string('name_key', 120);
                $table->string('inspection', 24)->default('Standard');
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['brand_id', 'name_key']);
            });
        }

        $stamp = ['created_at' => now(), 'updated_at' => now()];
        $catalogPath = __DIR__.'/../data/repair-catalog.php';
        if (file_exists($catalogPath)) {
            foreach (require $catalogPath as $brand => $profiles) {
                $existing = DB::table('repair_brands')->where('name_key', mb_strtolower($brand))->first();
                $brandId = $existing?->id ?? DB::table('repair_brands')->insertGetId(['name' => $brand, 'name_key' => mb_strtolower($brand)] + $stamp);

                foreach ($profiles as $inspection => $models) {
                    foreach ($models as $name) {
                        DB::table('repair_models')->insertOrIgnore([
                            'brand_id' => $brandId,
                            'name' => $name,
                            'name_key' => mb_strtolower($name),
                            'inspection' => $inspection,
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_models');
        Schema::dropIfExists('repair_brands');
    }
};
