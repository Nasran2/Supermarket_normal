<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('system')->default(false);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true)->index();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('group')->index();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('short_name', 20)->unique();
            $t->boolean('allow_decimal')->default(false);
            $t->boolean('active')->default(true)->index();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        foreach (['suppliers', 'customers'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name')->index();
                $t->string('phone')->nullable();
                $t->string('email')->nullable();
                $t->text('address')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->index();
            $t->string('sku')->unique();
            $t->string('barcode')->nullable()->unique();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->decimal('cost', 15, 2)->default(0);
            $t->decimal('price', 15, 2);
            $t->decimal('stock', 15, 3)->default(0);
            $t->decimal('low_stock', 15, 3)->default(5);
            $t->boolean('active')->default(true)->index();
            $t->string('image')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('code')->unique();
            $t->string('type')->index();
            $t->string('charge_bearer')->default('CUSTOMER');
            $t->boolean('active')->default(true)->index();
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
        });
        Schema::create('payment_charge_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->decimal('minimum_amount', 15, 2)->default(0);
            $t->decimal('maximum_amount', 15, 2)->nullable();
            $t->string('comparison_operator')->default('GTE');
            $t->string('charge_type');
            $t->decimal('charge_value', 15, 4);
            $t->string('charge_bearer');
            $t->integer('priority')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['payment_method_id', 'active', 'priority'], 'charge_rule_lookup');
        });
        Schema::create('registers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('open_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $t->decimal('opening_cash', 15, 2);
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->decimal('expected_cash', 15, 2)->nullable();
            $t->decimal('actual_cash', 15, 2)->nullable();
            $t->decimal('difference', 15, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('register_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('register_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->decimal('amount', 15, 2);
            $t->string('description');
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('invoice')->unique();
            $t->uuid('checkout_token')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->constrained()->restrictOnDelete();
            $t->decimal('subtotal', 15, 2);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('sale_amount', 15, 2);
            $t->decimal('processing_charge', 15, 2)->default(0);
            $t->decimal('customer_payable', 15, 2);
            $t->decimal('cost_total', 15, 2);
            $t->string('status')->default('ACTIVE')->index();
            $t->timestamp('sold_at')->index();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('sku');
            $t->string('unit');
            $t->decimal('quantity', 15, 3);
            $t->decimal('price', 15, 2);
            $t->decimal('cost', 15, 2);
            $t->decimal('total', 15, 2);
            $t->timestamps();
        });
        Schema::create('sale_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_charge_rule_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('method_name');
            $t->string('method_type');
            $t->string('rule_name')->nullable();
            $t->string('charge_type')->nullable();
            $t->decimal('charge_value', 15, 4)->default(0);
            $t->string('charge_bearer')->nullable();
            $t->decimal('sale_amount', 15, 2);
            $t->decimal('processing_charge', 15, 2)->default(0);
            $t->decimal('customer_payable', 15, 2);
            $t->decimal('amount_paid', 15, 2);
            $t->decimal('change', 15, 2)->default(0);
            $t->string('reference')->nullable();
            $t->timestamps();
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('system')->default(false);
            $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('sale_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type')->default('MANUAL');
            $t->string('status')->default('ACTIVE');
            $t->date('expense_date')->index();
            $t->string('reference')->nullable();
            $t->text('description');
            $t->decimal('amount', 15, 2);
            $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('purchase_date')->index();
            $t->decimal('total', 15, 2);
            $t->string('status')->default('ACTIVE');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('unit');
            $t->decimal('quantity', 15, 3);
            $t->decimal('cost', 15, 2);
            $t->decimal('total', 15, 2);
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 15, 3);
            $t->decimal('balance', 15, 3);
            $t->string('reason');
            $t->string('reference');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action')->index();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'stock_movements', 'purchase_items', 'purchases', 'expenses', 'expense_categories', 'sale_payments', 'sale_items', 'sales', 'register_movements', 'registers', 'payment_charge_rules', 'payment_methods', 'products', 'customers', 'suppliers', 'categories', 'units', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users',function (Blueprint $t) {
            $t->dropForeign(['role_id']);
            $t->dropColumn(['role_id', 'active']);
        });
        foreach (['permission_role', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
