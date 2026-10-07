<?php

namespace Database\Seeders;

use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentExampleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['CARD', 'Card processing fee', '0', 'GTE', '3'], ['QR', 'QR high value fee', '5000', 'GT', '10']] as [$code,$name,$min,$op,$value]) {
            $method = PaymentMethod::where('type', $code)->firstOrFail();
            if ($method->rules()->exists()) {
                continue;
            }
            PaymentChargeRule::firstOrCreate(['payment_method_id' => $method->id, 'name' => $name], ['minimum_amount' => $min, 'comparison_operator' => $op, 'charge_type' => 'PERCENTAGE', 'charge_value' => $value, 'charge_bearer' => null, 'priority' => 10, 'active' => true]);
        }
    }
}
