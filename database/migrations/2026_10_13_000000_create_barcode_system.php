<?php

use App\Services\BarcodePresetService;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The existing nullable products.barcode already has a UNIQUE index.
        Schema::table('products', fn (Blueprint $t) => $t->string('barcode_type', 12)->default('CODE128'));
        Schema::create('barcode_label_presets', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_default')->default(false);
            // Measurements are canonical decimal strings in mm, never JSON floats.
            $t->json('configuration');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('barcode_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users');
            $t->string('preset_name');
            $t->json('configuration');
            $t->json('labels');
            $t->unsignedInteger('total_labels');
            $t->timestamps();
        });
        // A permanent lock row serializes preset default changes, including the first preset.
        DB::table('document_sequences')->insertOrIgnore(['key' => 'barcode-presets', 'next_number' => 1]);
        $defaults = app(BarcodePresetService::class)->defaults();
        foreach (['49 × 25 mm - 2 Across' => $defaults, '38 × 25 mm - 1 Across' => array_replace($defaults, ['sticker_width' => '38.00', 'paper_width' => '40.00', 'labels_per_row' => 1]), '50 × 30 mm - 2 Across' => array_replace($defaults, ['sticker_width' => '50.00', 'sticker_height' => '30.00', 'paper_width' => '104.00'])] as $name => $configuration) {
            DB::table('barcode_label_presets')->insert(['name' => $name, 'is_default' => $name === '49 × 25 mm - 2 Across', 'configuration' => json_encode($configuration), 'created_at' => now(), 'updated_at' => now()]);
        }
        if (Schema::hasTable('demo_cycles')) {
            foreach (DB::table('demo_cycles')->get() as $cycle) {
                $baseline = json_decode($cycle->baseline, true, flags: JSON_THROW_ON_ERROR);
                $baseline['barcode_print_jobs'] ??= [];
                foreach ($baseline['products'] ?? [] as &$product) {
                    $product['barcode_type'] ??= 'CODE128';
                }
                unset($product);
                $baseline['document_sequences'][] = ['key' => 'barcode-presets', 'next_number' => 1];
                DB::table('demo_cycles')->where('id', $cycle->id)->update(['baseline' => json_encode($baseline, JSON_THROW_ON_ERROR)]);
            }
        }
        Permissions::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_print_jobs');
        Schema::dropIfExists('barcode_label_presets');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('barcode_type'));
    }
};
