<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SmsMessage;
use App\Support\Demo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SmsService
{
    public function ready(?string $kind = null): void
    {
        abort_unless(config('sms.enabled'), 404);
        if (! app(SmsSettingsService::class)->allows($kind)) {
            throw ValidationException::withMessages(['sms' => 'Sending this message type is disabled in SMS settings.']);
        }
        if (Demo::enabled()) {
            throw ValidationException::withMessages(['sms' => 'SMS sending is unavailable in demo mode.']);
        }
        $credentials = app(SmsSettingsService::class)->credentials();
        if (! filled($credentials['username']) || ! filled($credentials['password'])) {
            throw ValidationException::withMessages(['sms' => 'Configure your Textit username and password in Settings → SMS before sending.']);
        }
    }

    public function phone(?string $phone): string
    {
        $phone = preg_replace('/[\s()+.\-]/', '', $phone ?? '');
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        if (preg_match('/^0[1-9][0-9]{8}$/', $phone)) {
            $phone = '94'.substr($phone, 1);
        } elseif (preg_match('/^7[0-9]{8}$/', $phone)) {
            $phone = '94'.$phone;
        }
        if (! preg_match('/^[1-9][0-9]{7,14}$/', $phone) || (str_starts_with($phone, '94') && strlen($phone) !== 11)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, e.g. 0771234567 or +94771234567.']);
        }

        return $phone;
    }

    public function matchingCustomer(string $phone): ?Customer
    {
        $variants = [$phone, '+'.$phone, '00'.$phone];
        if (str_starts_with($phone, '94') && strlen($phone) === 11) {
            $variants[] = '0'.substr($phone, 2);
            $variants[] = substr($phone, 2);
        }
        $column = 'phone';
        foreach ([' ', '-', '(', ')', '.'] as $character) {
            $column = "REPLACE($column, '$character', '')";
        }

        return Customer::whereRaw("$column IN (".implode(',', array_fill(0, count($variants), '?')).')', $variants)->first();
    }

    public function receiptLink(Sale $sale): string
    {
        $token = DB::transaction(function () use ($sale) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $locked->receipt_token) {
                $locked->receipt_token = Str::random(64);
                $locked->save();
            }

            return $locked->receipt_token;
        });

        // APP_URL includes the installation subdirectory, if any. Avoid adding the request base path twice.
        return rtrim(config('app.url'), '/').'/r/'.$token;
    }

    public function enqueue(array $data): SmsMessage
    {
        $this->ready($data['kind']);
        $data['phone'] = $this->phone($data['phone']);

        return SmsMessage::firstOrCreate(['dedupe_key' => $data['dedupe_key']], $data);
    }

    public function deliver(SmsMessage $message): SmsMessage
    {
        abort_unless(config('sms.enabled'), 404);
        if (! app(SmsSettingsService::class)->allows($message->kind)) {
            SmsMessage::whereKey($message->id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'error' => 'This message type was disabled in SMS settings.']);

            return $message->fresh();
        }
        $this->ready($message->kind);
        if (str_starts_with($message->kind, 'CHEQUE') && (! config('features.cheque') || ! Cheque::dueSoon()->whereKey($message->cheque_id)->exists())) {
            SmsMessage::whereKey($message->id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'error' => 'Cheque no longer requires a reminder.']);

            return $message->fresh();
        }
        // Claim once. Never automatically retry an uncertain gateway response: it may already have been charged/sent.
        if (! SmsMessage::whereKey($message->id)->where('status', 'PENDING')->update(['status' => 'SENDING', 'updated_at' => now()])) {
            return $message->fresh();
        }
        try {
            $credentials = app(SmsSettingsService::class)->credentials();
            $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://textit.biz/sendmsg/', [
                'id' => $credentials['username'], 'pw' => $credentials['password'],
                'to' => $message->phone, 'text' => $message->message, 'ref' => 'SM'.$message->id,
            ]);
            $parts = explode(':', trim($response->body()), 2);
            if ($response->successful() && ($parts[0] ?? '') === 'OK' && filled($parts[1] ?? null)) {
                $message->update(['status' => 'SENT', 'provider_id' => substr(trim($parts[1]), 0, 100), 'sent_at' => now(), 'error' => null]);
            } elseif ($response->successful() && in_array(strtoupper(trim($parts[0] ?? '')), ['ERROR', 'ERR', 'FAIL', 'FAILED'])) {
                $message->update(['status' => 'FAILED', 'error' => 'Textit rejected this message. Check the Textit account and message report.']);
            } else {
                $message->update(['status' => 'UNKNOWN', 'error' => 'No confirmed gateway result. Check Textit history before sending again.']);
            }
        } catch (ConnectionException $exception) {
            $message->update(['status' => 'UNKNOWN', 'error' => 'Gateway connection interrupted. Check Textit history before sending again.']);
        }

        return $message->fresh();
    }
}
