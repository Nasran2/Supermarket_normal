<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Older MariaDB versions otherwise replace the invoice date on edit or void.
            $table->timestamp('sold_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Retain protection for existing invoice dates.
    }
};
