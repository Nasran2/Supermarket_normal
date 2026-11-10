<?php

namespace App\Services\Repair;

use App\Models\Customer;
use App\Models\RepairDevice;
use App\Models\RepairJob;
use App\Models\RepairModel;
use App\Models\User;
use App\Support\Money;
use App\Support\Repairs;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairWorkflow
{
    public function __construct(private RepairSms $sms) {}

    public function receive(#[\SensitiveParameter] array $data, User $user): RepairJob
    {
        return DB::transaction(function () use ($data, $user) {
            $customer = Customer::where('active', true)->whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
            if ($existing = RepairJob::where('submission', $data['submission'])->first()) {
                abort_unless($existing->user_id === $user->id && $existing->customer_id === $customer->id, 403);

                return $existing;
            }
            if (! empty($data['device_id'])) {
                $device = RepairDevice::where('customer_id', $customer->id)->whereHas('jobs', fn ($q) => $q->visibleTo($user))->findOrFail($data['device_id']);
            } else {
                $details = Arr::only($data, ['brand', 'model', 'serial', 'color']) + ['customer_id' => $customer->id];
                if (isset($data['catalog_model_id'])) {
                    $model = RepairModel::with('brand')->where('active', true)->whereHas('brand', fn ($query) => $query->where('active', true))->find($data['catalog_model_id']);
                    if (! $model) {
                        throw ValidationException::withMessages(['catalog_model_id' => 'This model is no longer available. Select another model or enter it manually.']);
                    }
                    $details['brand'] = $model->brand->name;
                    $details['model'] = $model->name;
                }
                $device = empty($details['serial']) ? null : RepairDevice::where('customer_id', $customer->id)->where('serial', $details['serial'])
                    ->whereHas('jobs', fn ($q) => $q->visibleTo($user))->first();
                $device ??= RepairDevice::create($details);
            }
            $checklist = array_values(array_filter($data['checklist'] ?? [], fn ($row) => filled($row['label'] ?? null) && (filled($row['result'] ?? null) || filled($row['fault'] ?? null))));
            $job = RepairJob::create(Arr::only($data, ['submission', 'accessories', 'condition', 'faults', 'notes', 'received_at', 'due_at', 'technician_id', 'priority', 'warranty_days', 'estimate', 'device_unlock']) + [
                'tracking' => Repairs::nextTracking($data['received_at'] ?? today(), lock: true), 'status' => 'RECEIVED', 'user_id' => $user->id,
                'customer_id' => $customer->id, 'device_id' => $device->id, 'checklist' => $checklist,
                'unlock_type' => filled($data['device_unlock'] ?? null) ? $data['unlock_type'] : null,
                'sms_opt_in' => Repairs::sms() && (bool) ($data['sms_opt_in'] ?? false),
            ]);
            $event = $job->events()->create(['kind' => 'STATUS', 'public_status' => 'RECEIVED', 'user_id' => $user->id]);
            $this->sms->queue($job, 'REPAIR_INTAKE', $user->id, $event->id);

            return $job;
        }, 3);
    }

    public function update(RepairJob $job, array $data, User $user): void
    {
        DB::transaction(function () use ($job, $data, $user) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            $status = $data['status'];
            $changesStatus = $status !== $job->status;
            $wasEditable = $job->editable();
            if ((! $changesStatus && ! $wasEditable) || ($changesStatus && ! in_array($status, Repairs::transitions($job->status), true))) {
                throw ValidationException::withMessages(['status' => 'The job status changed or this transition is unavailable. Refresh the page.']);
            }
            if (in_array($status, ['CANCELLED', 'UNREPAIRABLE'], true) && (blank($data['activity_note'] ?? null) || Money::compare(Money::add($job->paid, $job->chequeHold()), 0) !== 0)) {
                throw ValidationException::withMessages(['status' => 'Record the reason and refund remaining advances or cancel pending cheques before closing this job.']);
            }

            if ($wasEditable) {
                $fields = Arr::only($data, ['technician_id', 'priority', 'due_at', 'warranty_days', 'estimate', 'diagnosis', 'labor', 'service_fee', 'discount', 'tax']);
                $before = Arr::only($job->getAttributes(), array_keys($fields));
                $approved = (bool) ($data['approved'] ?? false);
                $quoteChanged = collect(['estimate', 'labor', 'service_fee', 'discount', 'tax'])->contains(fn ($key) => array_key_exists($key, $fields)
                    && (($fields[$key] === null) !== ($job->$key === null) || Money::compare((string) ($fields[$key] ?? '0'), $job->$key ?? '0') !== 0));
                if (array_key_exists('approved', $data) || $quoteChanged) {
                    $fields['approved_at'] = $approved ? ($quoteChanged ? now() : ($job->approved_at ?? now())) : null;
                }
                $job->fill($fields);
                $details = $job->getDirty();
                if ($details) {
                    $job->events()->create(['kind' => 'UPDATE', 'user_id' => $user->id, 'details' => ['before' => Arr::only($before + ['approved_at' => $job->getRawOriginal('approved_at')], array_keys($details)), 'after' => $details]]);
                }
            }
            if (Repairs::sms() && array_key_exists('sms_opt_in', $data)) {
                $job->sms_opt_in = (bool) $data['sms_opt_in'];
                if ($job->isDirty('sms_opt_in')) {
                    $job->events()->create(['kind' => 'CONSENT', 'user_id' => $user->id, 'note' => $job->sms_opt_in ? 'SMS consent granted' : 'SMS consent withdrawn']);
                }
            }
            if ($status === 'COMPLETED') {
                $this->invoice($job);
            }
            if ($status === 'DELIVERED' && $job->warranty_days) {
                $job->warranty_until = today()->addDays($job->warranty_days);
            }
            if ($changesStatus) {
                $job->status = $status;
                if (! $job->editable() && ! $job->closed_at) {
                    $job->closed_at = now();
                }
                if ($status === 'DELIVERED' || in_array($status, ['CANCELLED', 'UNREPAIRABLE'], true)) {
                    $job->device_unlock = $job->unlock_type = null;
                }
            }
            $job->save();
            if ($changesStatus || filled($data['activity_note'] ?? null)) {
                $event = $job->events()->create(['kind' => $changesStatus ? 'STATUS' : 'NOTE', 'user_id' => $user->id, 'public_status' => $changesStatus ? $status : null, 'note' => $data['activity_note'] ?? null]);
                if ($changesStatus) {
                    $kind = match ($status) {
                        'READY' => 'REPAIR_READY', 'DELIVERED' => 'REPAIR_DELIVERY', default => 'REPAIR_STATUS',
                    };
                    $this->sms->queue($job, $kind, $user->id, $event->id);
                }
            }
        }, 3);
    }

    private function invoice(RepairJob $job): void
    {
        if ($job->parts()->where('state', 'PLANNED')->exists()) {
            throw ValidationException::withMessages(['status' => 'Consume or remove every planned part before finalizing.']);
        }
        $parts = $job->parts()->where('state', 'CONSUMED')->get();
        $subtotal = Money::sum([$job->labor, $job->service_fee, ...$parts->map(fn ($p) => Money::mul($p->quantity, $p->price))->all()]);
        $revenue = Money::sub($subtotal, $job->discount);
        $total = Money::add($revenue, $job->tax);
        if (Money::compare($revenue, 0) < 0 || Money::compare(Money::add($job->paid, $job->chequeHold()), $total) > 0) {
            throw ValidationException::withMessages(['status' => 'Check the discount and check that advances plus pending cheques fit within the final total.']);
        }
        if (Money::compare($total, '99999999999999.99') > 0) {
            throw ValidationException::withMessages(['status' => 'The invoice exceeds the supported amount. Review its quantities and charges.']);
        }
        $job->fill([
            'net_revenue' => $revenue, 'total' => $total, 'invoiced_at' => now(),
            'invoice_customer' => $job->customer->only(['name', 'phone', 'address']),
            'warranty_until' => $job->warranty_days ? today()->addDays($job->warranty_days) : null,
        ]);
    }
}
