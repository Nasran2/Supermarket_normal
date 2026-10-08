<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

class SaleLineService
{
    public function billDiscount(array $data, string $remainingSubtotal): string
    {
        if (! isset($data['bill_discount_type'])) {
            return Money::round((string) ($data['discount'] ?? 0));
        }
        $value = Money::round((string) ($data['bill_discount_value'] ?? 0));
        if ($data['bill_discount_type'] === 'PERCENT' && Money::compare($value, 100) > 0) {
            throw ValidationException::withMessages(['bill_discount_value' => 'Bill discount percentage cannot exceed 100%.']);
        }
        $discount = $data['bill_discount_type'] === 'PERCENT' ? Money::percent($remainingSubtotal, $value) : $value;
        if (Money::compare($discount, $remainingSubtotal) > 0) {
            throw ValidationException::withMessages(['bill_discount_value' => 'Bill discount cannot exceed the amount after line discounts.']);
        }

        return $discount;
    }

    public function calculate(array $item, string $catalogPrice): array
    {
        $price = Money::round((string) ($item['unit_price'] ?? $catalogPrice));
        $subtotal = Money::mul($price, (string) $item['quantity']);
        $type = $item['discount_type'] ?? 'AMOUNT';
        $value = Money::round((string) ($item['discount_value'] ?? 0));
        if ($type === 'PERCENT' && Money::compare($value, 100) > 0) {
            throw ValidationException::withMessages(['items' => 'Line discount percentage cannot exceed 100%.']);
        }
        $discount = $type === 'PERCENT' ? Money::percent($subtotal, $value) : $value;
        if (Money::compare($discount, $subtotal) > 0) {
            throw ValidationException::withMessages(['items' => 'Line discount cannot exceed the line amount.']);
        }

        return ['catalog_price' => $catalogPrice, 'price' => $price, 'line_subtotal' => $subtotal, 'line_discount' => $discount, 'discount_type' => $type, 'discount_value' => $value, 'total' => Money::sub($subtotal, $discount)];
    }
}
