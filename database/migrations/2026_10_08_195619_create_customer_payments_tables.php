<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('opening_due_paid', 15, 2)->default(0)->after('opening_due');
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('register_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('payment_method_id')->constrained();
            $table->decimal('amount', 15, 2);
            $table->timestamp('payment_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // 'OPENING_BALANCE', 'INVOICE'
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('opening_due_paid');
        });
    }
};
