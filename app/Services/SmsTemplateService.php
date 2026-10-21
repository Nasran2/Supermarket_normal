<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SmsTemplateService
{
    public function validate(string $kind, string $template, string $field = 'message'): void
    {
        preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        $unknown = array_diff($matches[1], config('sms.types.'.$kind.'.tokens', []));
        if ($unknown) {
            throw ValidationException::withMessages([$field => 'Unsupported placeholders: '.implode(', ', array_map(fn ($token) => '{'.$token.'}', array_unique($unknown))).'. Use the placeholders shown for this message type.']);
        }
    }

    public function render(string $kind, array $values, ?string $override = null): string
    {
        $template = $override ?? app(SmsSettingsService::class)->template($kind);
        $this->validate($kind, $template);
        $business = app(SettingsService::class);
        $values += ['business' => $business->get('business_name', config('app.name')), 'currency' => $business->get('currency_symbol', 'Rs.')];
        $replace = [];
        foreach (config('sms.types.'.$kind.'.tokens', []) as $token) {
            $replace['{'.$token.'}'] = (string) ($values[$token] ?? '');
        }
        $message = strtr($template, $replace);
        if (mb_strlen($message) > 1600) {
            throw ValidationException::withMessages(['message' => 'The message is too long after inserting customer details. Shorten the template.']);
        }

        return $message;
    }
}
