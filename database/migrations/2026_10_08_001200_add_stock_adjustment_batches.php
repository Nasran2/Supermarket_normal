<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('reason');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('unit');
            $table->decimal('stock_before', 15, 3);
            $table->decimal('stock_after', 15, 3);
            $table->decimal('quantity_change', 15, 3);
            $table->decimal('price_before', 15, 2);
            $table->decimal('price_after', 15, 2);
            $table->decimal('cost_before', 15, 2);
            $table->decimal('cost_after', 15, 2);
            $table->boolean('price_changed')->default(false);
            $table->boolean('cost_changed')->default(false);
            $table->timestamps();
            $table->unique(['stock_adjustment_id', 'product_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('stock_adjustments')->exists()) {
            throw new RuntimeException('Stock adjustment history exists; rollback would discard it.');
        }
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
