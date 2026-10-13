<?php

use App\Models\PaymentMethod;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('bank_name', 150);
            $t->string('account_name', 150);
            $t->string('account_number', 80);
            $t->string('branch', 150)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['bank_name', 'account_number']);
        });
        Schema::create('cheques', function (Blueprint $t) {
            $t->id();
            $t->string('instrument_key', 64)->unique();
            $t->string('cheque_number', 80);
            $t->string('type', 30);
            $t->decimal('amount', 15, 2);
            $t->date('cheque_date')->index();
            $t->string('bank_name', 150);
            $t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('branch', 150)->nullable();
            $t->string('drawer_name', 150)->nullable();
            $t->string('account_number', 80)->nullable();
            $t->foreignId('origin_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $t->foreignId('destination_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('PENDING')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            foreach (['passed', 'returned', 'cancelled'] as $event) {
                $t->foreignId($event.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp($event.'_at')->nullable();
            }
            $t->string('return_reason')->nullable();
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->string('image_path')->nullable();
            $t->timestamps();
        });
        Schema::create('cheque_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cheque_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('event', 30);
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        foreach (['sale_payments', 'sale_collections', 'customer_payments', 'purchase_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('cheque_id')->nullable()->constrained()->restrictOnDelete();
                $t->string('settlement_status', 20)->default('PASSED')->index();
            });
        }
        $method = PaymentMethod::withoutGlobalScopes()->where('code', 'CHEQUE')->orWhere('name', 'Cheque')->first();
        if ($method) {
            $method->update(['type' => 'CHEQUE', 'has_charge' => false]);
        } else {
            PaymentMethod::withoutGlobalScopes()->create(['code' => 'CHEQUE', 'name' => 'Cheque', 'type' => 'CHEQUE', 'active' => true, 'display_order' => 50, 'has_charge' => false, 'charge_bearer' => 'BUSINESS']);
        }
        Permissions::install();
    }

    public function down(): void
    {
        foreach (['sale_payments', 'sale_collections', 'customer_payments', 'purchase_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('cheque_id');
                $t->dropColumn('settlement_status');
            });
        }
        Schema::dropIfExists('cheque_events');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('bank_accounts');
    }
};
