<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('name_key', 80)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('repair_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('repair_brands')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_key', 120);
            $table->string('inspection', 24)->default('Standard');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['brand_id', 'name_key']);
        });

        $stamp = ['created_at' => now(), 'updated_at' => now()];
        foreach (require __DIR__.'/../data/repair-catalog.php' as $brand => $profiles) {
            $brandId = DB::table('repair_brands')->insertGetId(['name' => $brand, 'name_key' => mb_strtolower($brand)] + $stamp);
            foreach ($profiles as $inspection => $models) {
                DB::table('repair_models')->insert(array_map(fn ($name) => [
                    'brand_id' => $brandId, 'name' => $name, 'name_key' => mb_strtolower($name), 'inspection' => $inspection,
                ] + $stamp, $models));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_models');
        Schema::dropIfExists('repair_brands');
    }
};
