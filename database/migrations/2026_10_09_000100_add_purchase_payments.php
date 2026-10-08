<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->boolean('payment_tracking')->default(false)->index();
        });
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('register_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('token')->unique();
            $table->string('kind', 20)->default('PAYMENT');
            $table->string('method_name');
            $table->string('method_type', 30);
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->index();
            $table->timestamps();
            $table->index(['purchase_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('payment_tracking'));
    }
};
