<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $t) {
            $t->foreignId('register_id')->nullable()->change();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('replacement_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $this->documentFields($t);
            $t->decimal('customer_due_applied', 15, 2)->default(0);
            $t->decimal('fee_refund', 15, 2)->default(0);
        });
        Schema::table('sale_return_items', function (Blueprint $t) {
            $t->string('stock_action', 30)->default('RESTOCK');
            $t->text('reason')->nullable();
        });
        Schema::create('purchase_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('replacement_purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('token')->unique();
            $t->string('reference')->unique();
            $t->text('reason');
            foreach (['amount', 'cost_total', 'due_reduction', 'refund_amount', 'supplier_due_applied', 'supplier_credit'] as $field) {
                $t->decimal($field, 15, 2)->default(0);
            }
            $this->documentFields($t);
            $t->timestamp('returned_at')->useCurrent()->index();
            $t->timestamps();
        });
        Schema::create('purchase_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            foreach (['quantity', 'base_quantity'] as $field) {
                $t->decimal($field, 18, 3);
            }
            foreach (['amount', 'cost_total'] as $field) {
                $t->decimal($field, 15, 2);
            }
            $t->timestamps();
            $t->unique(['purchase_return_id', 'purchase_item_id']);
        });
        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('PENDING');
            $t->decimal('amount', 15, 2)->default(0);
            $t->text('notes')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('return_stock_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('purchase_return_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('supplier_return_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('sale_stock_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('stock_layer_id')->constrained('product_stock_layers')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('cost_total', 15, 2);
            $t->string('stock_action', 30);
            $t->timestamps();
        });
        Schema::create('return_account_allocations', function (Blueprint $t) {
            $t->id();
            foreach (['sale_return', 'purchase_return', 'supplier_return', 'sale', 'purchase', 'customer', 'supplier'] as $parent) {
                $t->foreignId($parent.'_id')->nullable()->constrained()->restrictOnDelete();
            }
            $t->string('kind', 30);
            $t->decimal('amount', 15, 2);
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('return_settlements', function (Blueprint $t) {
            $t->id();
            foreach (['sale_return', 'purchase_return', 'supplier_return', 'register', 'payment_method', 'register_movement'] as $parent) {
                $t->foreignId($parent.'_id')->nullable()->constrained()->restrictOnDelete();
            }
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('method_name');
            $t->string('method_type');
            // Positive = cash received by the shop. Negative = cash paid out.
            $t->decimal('amount', 15, 2);
            $t->decimal('processing_charge', 15, 2)->default(0);
            $t->timestamps();
        });
        foreach (config('pos.returns') as $key => $field) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'group' => 'returns', 'value' => json_encode($field[2]), 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget('business_settings');
        Permissions::install();
    }

    private function documentFields(Blueprint $t): void
    {
        $t->string('status', 20)->default('COMPLETED')->index();
        $t->string('resolution', 20)->default('MONEY');
        $t->json('draft_payload')->nullable();
        $t->decimal('replacement_value', 15, 2)->default(0);
        $t->decimal('additional_payment', 15, 2)->default(0);
        $t->text('notes')->nullable();
        $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
        $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
        $t->timestamp('cancelled_at')->nullable();
        $t->text('cancel_reason')->nullable();
    }

    public function down(): void
    {
        throw new RuntimeException('Return history is financially auditable. Restore a verified backup rather than dropping return records.');
    }
};
