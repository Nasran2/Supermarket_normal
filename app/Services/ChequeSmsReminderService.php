<?php

namespace App\Services;

use App\Models\Cheque;
use App\Support\Cheques;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ChequeSmsReminderService
{
    public function queue(SmsService $sms): int
    {
        $settings = app(SmsSettingsService::class);
        if (! config('sms.enabled') || ! config('features.cheque') || (! $settings->allows('CHEQUE') && ! $settings->allows('CHEQUE_CUSTOMER'))) {
            return 0;
        }
        $sms->ready();
        $admins = [];
        if ($settings->allows('CHEQUE') && filled($settings->adminNumbers())) {
            $admins = array_unique(array_map(fn ($phone) => $sms->phone(trim($phone)), explode(',', $settings->adminNumbers())));
        }
        $count = 0;
        foreach (Cheque::dueSoon()->with('customer', 'supplier')->get() as $cheque) {
            $recipients = array_map(fn ($phone) => ['kind' => 'CHEQUE', 'phone' => $phone, 'customer_id' => null], $admins);
            if ($settings->allows('CHEQUE_CUSTOMER') && $cheque->customer?->active) {
                try {
                    $recipients[] = ['kind' => 'CHEQUE_CUSTOMER', 'phone' => $sms->phone($cheque->customer->phone), 'customer_id' => $cheque->customer->id];
                } catch (ValidationException $exception) {
                    // A missing customer phone must not prevent admin reminders.
                }
            }
            foreach ($recipients as $recipient) {
                $key = $recipient['kind'] === 'CHEQUE' ? 'cheque:' : 'cheque-customer:';
                $message = $sms->enqueue($recipient + [
                    'dedupe_key' => $key.Cheques::today()->toDateString().':'.$cheque->id.':'.$recipient['phone'],
                    'cheque_id' => $cheque->id,
                    'message' => app(SmsTemplateService::class)->render($recipient['kind'], [
                        'name' => $cheque->customer?->name ?? 'Administrator', 'cheque_number' => $cheque->cheque_number,
                        'cheque_type' => $cheque->type_label, 'bank' => $cheque->bank_name, 'amount' => Money::display($cheque->amount),
                        'date' => $cheque->cheque_date->format('d/m/Y'), 'overdue' => $cheque->overdue ? ' (overdue)' : '', 'parties' => $cheque->parties,
                    ]),
                ]);
                $count += (int) $message->wasRecentlyCreated;
            }
        }

        return $count;
    }
}
