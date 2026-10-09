<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_options', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20)->index();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'name']);
        });
        Schema::create('hr_staff', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable()->unique();
            $t->string('name')->index();
            foreach (['phone', 'email', 'address', 'emergency_contact', 'emergency_phone', 'bank_details'] as $field) {
                $t->text($field)->nullable();
            }
            $t->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            foreach (['department_id', 'position_id', 'shift_id'] as $field) {
                $t->foreignId($field)->nullable()->constrained('hr_options')->restrictOnDelete();
            }
            $t->date('joined_on');
            $t->date('left_on')->nullable();
            $t->string('status', 20)->default('ACTIVE')->index();
            $t->string('employment_type', 20)->default('PERMANENT');
            $t->string('salary_basis', 10)->default('MONTHLY');
            $t->decimal('salary_rate', 15, 2)->default(0);
            $t->decimal('overtime_rate', 15, 2)->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('hr_employment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $t->string('type', 20);
            $t->date('event_date');
            $t->text('reason')->nullable();
            $t->json('snapshot');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('hr_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $t->date('date')->index();
            $t->string('status', 20);
            $t->dateTime('check_in')->nullable();
            $t->dateTime('check_out')->nullable();
            $t->decimal('overtime_hours', 8, 2)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['staff_id', 'date']);
        });
        Schema::create('hr_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('hr_options')->restrictOnDelete();
            $t->date('from');
            $t->date('to');
            $t->unsignedInteger('days');
            $t->boolean('paid');
            $t->string('status', 20)->default('PENDING')->index();
            $t->text('reason');
            $t->text('decision_note')->nullable();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('hr_payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $t->date('period')->index();
            $t->string('reference')->nullable()->unique();
            $t->string('staff_name');
            $t->string('staff_code');
            $t->string('status', 20)->default('DRAFT')->index();
            $t->decimal('basic', 15, 2);
            $t->decimal('earnings', 15, 2);
            $t->decimal('deductions', 15, 2);
            $t->decimal('advance_recovery', 15, 2)->default(0);
            $t->decimal('net', 15, 2);
            $t->json('lines');
            $t->json('attendance_snapshot');
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('live_key')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('hr_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $t->foreignId('payroll_id')->nullable()->constrained('hr_payrolls')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('status', 20)->default('ACTIVE');
            $t->uuid('token')->unique();
            $t->date('date')->index();
            $t->decimal('amount', 15, 2);
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->string('method_name');
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->foreignId('register_id')->nullable()->constrained('registers')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        foreach (['department' => ['Store'], 'position' => ['Cashier', 'Store assistant', 'Manager'], 'leave_type' => ['Annual leave', 'Sick leave', 'Unpaid leave']] as $kind => $names) {
            foreach ($names as $name) {
                DB::table('hr_options')->insert(['kind' => $kind, 'name' => $name, 'active' => true, 'settings' => json_encode($kind === 'leave_type' ? ['paid' => $name !== 'Unpaid leave', 'annual_days' => 0] : []), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Permissions::install();
    }

    public function down(): void
    {
        if (DB::table('hr_staff')->exists()) {
            throw new RuntimeException('Staff history exists; preserve HR records instead of rolling back.');
        }
        foreach (['hr_payments', 'hr_payrolls', 'hr_leaves', 'hr_attendances', 'hr_employment_events', 'hr_staff', 'hr_options'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
