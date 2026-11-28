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
            if (! Schema::hasColumn('products', 'available_for_pos')) {
                $t->boolean('available_for_pos')->default(true);
            }
            if (! Schema::hasColumn('products', 'available_for_repairs')) {
                $t->boolean('available_for_repairs')->default(true);
            }
        });

        if (! Schema::hasTable('repair_devices') || ! Schema::hasColumn('repair_devices', 'brand')) {
            Schema::dropIfExists('repair_devices');
            Schema::create('repair_devices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('customer_id')->index();
                $t->string('brand', 80);
                $t->string('model', 120);
                $t->string('serial', 100)->nullable();
                $t->string('color', 60)->nullable();
                $t->timestamps();
                $t->index(['customer_id', 'serial']);
            });
        }

        if (! Schema::hasTable('repair_jobs') || ! Schema::hasColumn('repair_jobs', 'tracking')) {
            Schema::dropIfExists('repair_jobs');
            Schema::create('repair_jobs', function (Blueprint $t) {
                $t->id();
                $t->uuid('submission')->unique();
                $t->string('tracking', 32)->unique();
                $t->foreignId('device_id')->index();
                $t->foreignId('customer_id')->index();
                $t->foreignId('user_id')->index();
                $t->foreignId('technician_id')->nullable()->index();
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
        }

        if (Schema::hasTable('stock_movements') && ! Schema::hasColumn('stock_movements', 'repair_job_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->foreignId('repair_job_id')->nullable()->index());
        }

        if (Schema::hasTable('cheques')) {
            Schema::table('cheques', function (Blueprint $t) {
                if (! Schema::hasColumn('cheques', 'repair_job_id')) {
                    $t->foreignId('repair_job_id')->nullable()->index();
                }
                if (! Schema::hasColumn('cheques', 'repair_method_id')) {
                    $t->foreignId('repair_method_id')->nullable()->index();
                }
                if (! Schema::hasColumn('cheques', 'repair_submission')) {
                    $t->uuid('repair_submission')->nullable()->unique();
                }
            });
        }

        if (! Schema::hasTable('repair_events') || ! Schema::hasColumn('repair_events', 'kind')) {
            Schema::dropIfExists('repair_events');
            Schema::create('repair_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('repair_job_id')->index();
                $t->foreignId('user_id')->index();
                $t->string('kind', 25);
                $t->text('note')->nullable();
                $t->string('public_status', 25)->nullable();
                $t->json('details')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('repair_parts') || ! Schema::hasColumn('repair_parts', 'name')) {
            Schema::dropIfExists('repair_parts');
            Schema::create('repair_parts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('repair_job_id')->index();
                $t->foreignId('product_id')->index();
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
        }

        if (! Schema::hasTable('repair_payments') || ! Schema::hasColumn('repair_payments', 'amount')) {
            Schema::dropIfExists('repair_payments');
            Schema::create('repair_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('repair_job_id')->index();
                $t->foreignId('user_id')->index();
                $t->foreignId('register_id')->nullable()->index();
                $t->foreignId('payment_method_id')->nullable()->index();
                $t->foreignId('bank_account_id')->nullable()->index();
                $t->foreignId('reverses_id')->nullable()->unique();
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
        }

        if (! Schema::hasTable('repair_costs') || ! Schema::hasColumn('repair_costs', 'amount')) {
            Schema::dropIfExists('repair_costs');
            Schema::create('repair_costs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('repair_job_id')->index();
                $t->foreignId('user_id')->index();
                $t->foreignId('supplier_id')->nullable()->index();
                $t->foreignId('payment_method_id')->nullable()->index();
                $t->foreignId('bank_account_id')->nullable()->index();
                $t->foreignId('register_id')->nullable()->index();
                $t->uuid('submission')->unique();
                $t->string('description', 250);
                $t->decimal('amount', 16, 2);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('repair_attachments') || ! Schema::hasColumn('repair_attachments', 'path')) {
            Schema::dropIfExists('repair_attachments');
            Schema::create('repair_attachments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('repair_job_id')->index();
                $t->foreignId('user_id')->index();
                $t->string('name');
                $t->string('path');
                $t->timestamps();
            });
        }

        if (Schema::hasTable('sms_messages') && ! Schema::hasColumn('sms_messages', 'repair_job_id')) {
            Schema::table('sms_messages', fn (Blueprint $t) => $t->foreignId('repair_job_id')->nullable()->index());
        }

        foreach (array_keys(Repairs::PERMISSIONS) as $action) {
            DB::table('permissions')->insertOrIgnore(['name' => 'repairs.'.$action, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_messages') && Schema::hasColumn('sms_messages', 'repair_job_id')) {
            Schema::table('sms_messages', fn (Blueprint $t) => $t->dropColumn('repair_job_id'));
        }
        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'repair_job_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->dropColumn('repair_job_id'));
        }
        if (Schema::hasTable('cheques')) {
            Schema::table('cheques', function (Blueprint $t) {
                $cols = array_filter(['repair_job_id', 'repair_method_id', 'repair_submission'], fn ($c) => Schema::hasColumn('cheques', $c));
                if (! empty($cols)) {
                    $t->dropColumn($cols);
                }
            });
        }
        foreach (['repair_attachments', 'repair_costs', 'repair_payments', 'repair_parts', 'repair_events', 'repair_jobs', 'repair_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $t) {
                $cols = array_filter(['available_for_pos', 'available_for_repairs'], fn ($c) => Schema::hasColumn('products', $c));
                if (! empty($cols)) {
                    $t->dropColumn($cols);
                }
            });
        }
        DB::table('permissions')->where('name', 'like', 'repairs.%')->delete();
    }
};
