<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            // An explicit default prevents older MariaDB servers automatically
            // replacing the first TIMESTAMP whenever a register is closed.
            $table->timestamp('opened_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Keep historical opening times protected when rolling back.
    }
};
