<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('token')->unique();
            $t->json('before');
            $t->json('after');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('sale_revisions')->exists()) {
            throw new RuntimeException('Invoice revision history exists; rollback would discard it.');
        }
        Schema::dropIfExists('sale_revisions');
    }
};
