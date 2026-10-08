<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('token')->unique();
            $t->string('reference')->unique();
            $t->text('reason');
            $t->decimal('amount', 15, 2);
            $t->decimal('cost_total', 15, 2);
            $t->decimal('due_reduction', 15, 2);
            $t->decimal('refund_amount', 15, 2);
            $t->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('method_name')->nullable();
            $t->string('method_type')->nullable();
            $t->timestamp('returned_at')->useCurrent()->index();
            $t->timestamps();
        });
        Schema::create('sale_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 15, 3);
            $t->decimal('base_quantity', 15, 3);
            $t->decimal('amount', 15, 2);
            $t->decimal('cost_total', 15, 2);
            $t->timestamps();
            $t->unique(['sale_return_id', 'sale_item_id']);
        });
        Schema::create('sale_collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->uuid('token')->unique();
            $t->string('method_name');
            $t->string('method_type');
            $t->decimal('amount', 15, 2);
            $t->decimal('amount_paid', 15, 2);
            $t->decimal('change', 15, 2);
            $t->string('reference')->nullable();
            $t->timestamp('collected_at')->useCurrent()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists() || DB::table('sale_collections')->exists()) {
            throw new RuntimeException('Return or collection history exists; rollback would discard financial records.');
        }
        Schema::dropIfExists('sale_collections');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
