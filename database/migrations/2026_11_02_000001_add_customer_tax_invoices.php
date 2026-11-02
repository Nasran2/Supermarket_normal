<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('tax_number', 100)->nullable());
        Schema::table('sales', fn (Blueprint $table) => $table->json('tax')->nullable());
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoices');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('tax'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('tax_number'));
    }
};
