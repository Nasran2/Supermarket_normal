<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Expiry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExpiryService
{
    public function incoming(Product $product, array $row, bool $required = true, ?string $received = null): array
    {
        if (! Expiry::tracks($product)) {
            return ['expires_at' => null, 'batch_number' => null];
        }
        $values = Validator::make($row, ['expires_at' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d'], 'batch_number' => ['nullable', 'string', 'max:100']])->setAttributeNames(['expires_at' => 'expiry date', 'batch_number' => 'batch number'])->validate();
        $date = $values['expires_at'] ?? null;
        if ($date && (Expiry::expired($date) || ($received && $date <= substr($received, 0, 10)))) {
            throw ValidationException::withMessages(['items' => 'This batch is already expired or expires on the receiving date. Enter a later expiry date.']);
        }

        return ['expires_at' => $date, 'batch_number' => Expiry::setting('expiry_batch_enabled') ? ($values['batch_number'] ?? null) : null];
    }
}
