<?php

namespace App\Support;

use App\Services\SettingsService;
use Picqer\Barcode\BarcodeGeneratorPNG;

final class RepairReceipt
{
    public const DEFAULTS = ['size' => 'A5', 'layout' => 'classic', 'inspection' => true, 'barcode' => false, 'prices' => false];

    public static function rules(): array
    {
        return [
            'size' => 'nullable|in:80mm,A5,A4', 'layout' => 'nullable|in:classic,compact',
            'inspection' => 'sometimes|boolean', 'barcode' => 'sometimes|boolean', 'prices' => 'sometimes|boolean',
        ];
    }

    public static function settingsRules(): array
    {
        return ['terms' => 'nullable|string|max:3000', 'receipt' => 'sometimes|array:size,layout,inspection,barcode,prices']
            + collect(self::rules())->mapWithKeys(fn ($rule, $key) => ['receipt.'.$key => $rule])->all();
    }

    public static function barcode(string $tracking): string
    {
        return base64_encode((new BarcodeGeneratorPNG)->getBarcode($tracking, BarcodeGeneratorPNG::TYPE_CODE_128, 1, 40));
    }

    public static function options(array $overrides = [], bool $useSaved = true): array
    {
        $saved = $useSaved ? app(SettingsService::class)->get('repair_receipt', []) : [];

        return array_filter(array_intersect_key($overrides, self::DEFAULTS), fn ($value) => $value !== null)
            + $saved + self::DEFAULTS;
    }
}
