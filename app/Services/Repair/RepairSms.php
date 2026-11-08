<?php

namespace App\Services\Repair;

use App\Models\RepairJob;
use App\Services\SmsService;
use App\Services\SmsSettingsService;
use App\Services\SmsTemplateService;
use App\Support\Demo;
use App\Support\Repairs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairSms
{
    public function queue(RepairJob $job, string $kind, int $actor, int $event, array $values = []): void
    {
        if (! Repairs::smsEnabled() || Demo::enabled() || ! $job->sms_opt_in) {
            return;
        }

        try {
            if (! app(SmsSettingsService::class)->allows($kind)) {
                return;
            }
            DB::transaction(function () use ($job, $kind, $actor, $event, $values) {
                $customer = $job->customer;
                if (! $customer?->active || ! $customer->phone) {
                    return;
                }
                $status = Repairs::STATUS[$job->status];
                app(SmsService::class)->enqueue([
                    'kind' => $kind, 'dedupe_key' => 'repair:'.$kind.':'.$event,
                    'repair_job_id' => $job->id, 'user_id' => $actor, 'customer_id' => $customer->id,
                    'phone' => $customer->phone,
                    'message' => app(SmsTemplateService::class)->render($kind, $values + [
                        'tracking' => $job->tracking, 'status' => $status,
                        'tracking_url' => rtrim(config('app.url'), '/').'/repair-tracking', 'due_date' => $job->due_at?->format('d M Y') ?? 'To be confirmed',
                    ]),
                ]);
            });
        } catch (\Throwable) {
            Log::notice('Repair saved; SMS could not be queued.', ['repair_job_id' => $job->id, 'kind' => $kind]);
        }
    }
}
