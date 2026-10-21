<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;
use App\Support\Demo;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSmsService
{
    public function queue(?int $customerId, int $userId, string $amount, string $method, string $reference, ?Sale $sale = null, bool $pendingCheque = false): void
    {
        if (! $customerId || ! config('sms.enabled') || Demo::enabled()) {
            return;
        }
        // The outbox shares the payment transaction. No network request can delay or undo a payment.
        try {
            if (! app(SmsSettingsService::class)->allows('PAYMENT')) {
                return;
            }
            DB::transaction(function () use ($customerId, $userId, $amount, $method, $reference, $sale, $pendingCheque) {
                $customer = Customer::find($customerId);
                if (! $customer || ! $customer->active) {
                    return;
                }
                $sms = app(SmsService::class);
                $phone = $sms->phone($customer->phone);
                $template = app(SmsSettingsService::class)->template('PAYMENT');
                $link = $sale && str_contains($template, '{receipt_url}') ? $sms->receiptLink($sale) : '';
                $sms->enqueue([
                    'dedupe_key' => 'payment:'.$reference, 'kind' => 'PAYMENT', 'user_id' => $userId, 'customer_id' => $customerId, 'sale_id' => $sale?->id, 'phone' => $phone,
                    'message' => app(SmsTemplateService::class)->render('PAYMENT', [
                        'name' => $customer->name, 'amount' => Money::display($amount), 'due' => Money::display($customer->due_balance),
                        'invoice' => $sale?->invoice ?? 'Account payment', 'method' => $method,
                        'payment_status' => $pendingCheque ? 'Cheque pending clearance' : 'Payment confirmed',
                        'date' => now()->format('d/m/Y'), 'receipt_url' => $link,
                    ]),
                ]);
            });
        } catch (\Throwable $exception) {
            // Never log exception details here: a gateway configuration error may contain sensitive context.
            Log::notice('Payment recorded; SMS confirmation could not be queued.', ['reference' => $reference]);
        }
    }
}
