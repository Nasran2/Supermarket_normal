<?php

use App\Support\Repairs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('available_for_pos')->default(true);
            $t->boolean('available_for_repairs')->default(false);
        });
        Schema::create('repair_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('brand', 80);
            $t->string('model', 120);
            $t->string('serial', 100)->nullable();
            $t->string('color', 60)->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'serial']);
        });
        Schema::create('repair_jobs', function (Blueprint $t) {
            $t->id();
            $t->uuid('submission')->unique();
            $t->string('tracking', 32)->unique();
            $t->foreignId('device_id')->constrained('repair_devices')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 25)->index();
            $t->string('priority', 10);
            $t->string('accessories', 500)->nullable();
            $t->text('condition')->nullable();
            $t->text('faults');
            $t->text('notes')->nullable();
            $t->text('diagnosis')->nullable();
            $t->json('checklist')->nullable();
            $t->string('unlock_type', 10)->nullable();
            $t->text('device_unlock')->nullable();
            $t->boolean('sms_opt_in')->default(false);
            $t->dateTime('received_at')->index();
            $t->date('due_at')->nullable()->index();
            $t->dateTime('approved_at')->nullable();
            $t->unsignedSmallInteger('warranty_days')->default(0);
            $t->date('warranty_until')->nullable();
            $t->decimal('estimate', 16, 2)->nullable();
            foreach (['labor', 'service_fee', 'discount', 'tax', 'net_revenue', 'total', 'part_cost', 'paid', 'credited', 'tax_credited'] as $amount) {
                $t->decimal($amount, 16, 2)->default(0);
            }
            $t->json('invoice_customer')->nullable();
            $t->dateTime('invoiced_at')->nullable()->index();
            $t->dateTime('closed_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::table('stock_movements', fn (Blueprint $t) => $t->foreignId('repair_job_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('cheques', function (Blueprint $t) {
            $t->foreignId('repair_job_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('repair_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $t->uuid('repair_submission')->nullable()->unique();
        });
        Schema::create('repair_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('repair_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 25);
            $t->text('note')->nullable();
            $t->string('public_status', 25)->nullable();
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::create('repair_parts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('repair_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->uuid('submission')->unique();
            $t->string('name');
            $t->decimal('quantity', 15, 3);
            $t->decimal('price', 16, 2);
            $t->decimal('cost', 16, 2)->default(0);
            $t->string('state', 12)->default('PLANNED');
            $t->dateTime('consumed_at')->nullable();
            $t->json('allocations')->nullable();
            $t->timestamps();
        });
        Schema::create('repair_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('repair_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reverses_id')->nullable()->unique()->constrained('repair_payments')->restrictOnDelete();
            $t->uuid('submission')->nullable()->unique();
            $t->string('kind', 15);
            $t->string('method_type', 25);
            $t->string('method_name');
            $t->decimal('amount', 16, 2);
            $t->decimal('credit', 16, 2)->default(0);
            $t->decimal('tax_credit', 16, 2)->default(0);
            $t->string('reference', 120)->nullable();
            $t->string('reason', 500)->nullable();
            $t->timestamps();
            $t->index(['created_at', 'payment_method_id']);
        });
        Schema::create('repair_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('repair_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('register_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('submission')->unique();
            $t->string('description', 250);
            $t->decimal('amount', 16, 2);
            $t->timestamps();
        });
        Schema::create('repair_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('repair_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('path');
            $t->timestamps();
        });
        Schema::table('sms_messages', fn (Blueprint $t) => $t->foreignId('repair_job_id')->nullable()->constrained()->restrictOnDelete());
        foreach (array_keys(Repairs::PERMISSIONS) as $action) {
            DB::table('permissions')->insertOrIgnore(['name' => 'repairs.'.$action, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('sms_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('repair_job_id'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('repair_job_id'));
        Schema::table('cheques', function (Blueprint $t) {
            $t->dropConstrainedForeignId('repair_job_id');
            $t->dropConstrainedForeignId('repair_method_id');
            $t->dropUnique(['repair_submission']);
        });
        Schema::table('cheques', fn (Blueprint $t) => $t->dropColumn('repair_submission'));
        foreach (['repair_attachments', 'repair_costs', 'repair_payments', 'repair_parts', 'repair_events', 'repair_jobs', 'repair_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['available_for_pos', 'available_for_repairs']));
        DB::table('permissions')->where('name', 'like', 'repairs.%')->delete();
    }
};
