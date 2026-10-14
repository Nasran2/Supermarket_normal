<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cheque_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('token')->unique();
            $table->string('request_hash', 64);
            $table->string('resolution', 20);
            $table->string('side', 20);
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['cheque_id', 'side']);
        });
        Schema::create('cheque_resolution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cheque_resolution_id')->constrained()->restrictOnDelete();
            $table->foreignId('replacement_cheque_id')->constrained('cheques')->restrictOnDelete();
            $table->unique(['cheque_resolution_id', 'replacement_cheque_id'], 'cheque_resolution_replacement_unique');
        });
        // Existing demo snapshots predate these empty tables. Preserve their business data.
        if (Schema::hasTable('demo_cycles')) {
            foreach (DB::table('demo_cycles')->get() as $cycle) {
                $baseline = json_decode($cycle->baseline, true, flags: JSON_THROW_ON_ERROR);
                $baseline += ['cheque_resolutions' => [], 'cheque_resolution_items' => []];
                DB::table('demo_cycles')->where('id', $cycle->id)->update(['baseline' => json_encode($baseline, JSON_THROW_ON_ERROR)]);
            }
        }
        Permissions::install();
    }

    public function down(): void
    {
        if (DB::table('cheque_resolutions')->exists()) {
            throw new RuntimeException('Cheque resolutions exist. Rollback would discard return history.');
        }
        Schema::dropIfExists('cheque_resolution_items');
        Schema::dropIfExists('cheque_resolutions');
    }
};
