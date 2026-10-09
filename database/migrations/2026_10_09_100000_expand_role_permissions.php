<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permissions::install();
    }

    public function down(): void
    {
        // Preserve role assignments and auditability when rolling back application code.
    }
};
