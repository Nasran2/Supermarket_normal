<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Types\TypeEan8;
use Picqer\Barcode\Types\TypeUpcA;

class BarcodeService
{
    public const TYPES = ['CODE128', 'EAN13', 'EAN8', 'UPCA'];

    public function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, config('barcode.settings.'.$key.'.2', $default));
    }

    public function validate(string $code, string $type = 'CODE128'): void
    {
        $valid = in_array($type, self::TYPES, true);
        if ($type === 'CODE128') {
            $valid = $valid && preg_match('/^[\x20-\x7E]{1,80}$/D', $code);
        } else {
            $length = ['EAN13' => 13, 'EAN8' => 8, 'UPCA' => 12][$type] ?? 0;
            $valid = $valid && preg_match('/^[0-9]{'.$length.'}$/D', $code) && substr($code, -1) === $this->checkDigit(substr($code, 0, -1));
        }
        if (! $valid) {
            throw ValidationException::withMessages(['barcode' => 'Enter a valid '.$type.' barcode (including its check digit for EAN/UPC).']);
        }
    }

    private function checkDigit(string $digits): string
    {
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    public function unique(string $code, ?int $ignore = null): void
    {
        $other = Product::where('barcode', $code)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->first();
        if ($other) {
            throw ValidationException::withMessages(['barcode' => 'This barcode is already assigned to '.$other->name.'.']);
        }
    }

    public function generate(?string $type = null): string
    {
        abort_unless(config('features.barcode'), 404);
        if (! $this->setting('barcode_auto_generate', true)) {
            throw ValidationException::withMessages(['barcode' => 'Automatic barcode generation is disabled in Barcode Settings.']);
        }
        $type ??= $this->setting('barcode_default_type', 'CODE128');

        return DB::transaction(function () use ($type) {
            $prefix = (string) $this->setting('barcode_prefix', '200');
            $length = $type === 'CODE128' ? (int) $this->setting('barcode_length', 13) : ['EAN13' => 12, 'EAN8' => 7, 'UPCA' => 11][$type];
            if (! preg_match('/^[0-9]*$/D', $prefix) || strlen($prefix) >= $length) {
                throw ValidationException::withMessages(['barcode_prefix' => 'The numeric prefix must leave space for the generated sequence.']);
            }
            $key = 'barcode-'.$type.'-'.$prefix;
            DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'next_number' => 1]);
            $n = (int) DB::table('document_sequences')->where('key', $key)->lockForUpdate()->value('next_number');
            do {
                if (strlen((string) $n) > $length - strlen($prefix)) {
                    throw ValidationException::withMessages(['barcode' => 'This barcode sequence is full. Change the prefix or length.']);
                }
                $code = $prefix.str_pad((string) $n++, $length - strlen($prefix), '0', STR_PAD_LEFT);
                if ($type !== 'CODE128') {
                    $code .= $this->checkDigit($code);
                }
            } while (Product::where('barcode', $code)->exists());
            DB::table('document_sequences')->where('key', $key)->update(['next_number' => $n]);
            $this->validate($code, $type);

            return $code;
        }, 3);
    }

    public function assign(Product $product, ?string $code = null, ?string $type = null): Product
    {
        try {
            return DB::transaction(function () use ($product, $code, $type) {
                $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                abort_unless(config('features.barcode'), 404);
                abort_unless(auth()->user()?->hasPermission($code === null ? 'barcode.generate' : 'barcode.edit_product_barcode'), 403);
                if ($p->barcode) {
                    abort_unless(auth()->user()?->hasPermission('barcode.edit_product_barcode'), 403);
                }
                $before = ['barcode' => $p->barcode, 'barcode_type' => $p->barcode_type];
                $type ??= $this->setting('barcode_default_type', 'CODE128');
                $code ??= $this->generate($type);
                $this->validate($code, $type);
                $this->unique($code, $p->id);
                $p->update(['barcode' => $code, 'barcode_type' => $type]);
                Audit::record('barcode.assign', $p, $before, ['barcode' => $code, 'barcode_type' => $type]);

                return $p;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($code !== null) {
                $this->unique($code, $product->id);
            }
            throw ValidationException::withMessages(['barcode' => 'This barcode was assigned by another request. Please generate a new code.']);
        }
    }

    public function svg(string $code, string $type, string $widthMm, string $heightMm): string
    {
        $this->validate($code, $type);
        $generator = new BarcodeGeneratorSVG;
        $format = match ($type) {
            'EAN13' => $generator::TYPE_EAN_13, 'EAN8' => $generator::TYPE_EAN_8, 'UPCA' => $generator::TYPE_UPC_A, default => $generator::TYPE_CODE_128
        };
        $encoder = match ($type) {
            'EAN13' => new TypeEan13, 'EAN8' => new TypeEan8, 'UPCA' => new TypeUpcA, default => new TypeCode128
        };
        $barcode = $encoder->getBarcode($code);
        // Reserve quiet zones; EAN-13 needs eleven modules on its left.
        $modules = $barcode->getWidth();
        $quietLeft = $type === 'EAN13' ? 11 : 10;
        $quietRight = 10;
        $scale = (float) $widthMm / ($modules + $quietLeft + $quietRight);
        if ($scale < ($type === 'CODE128' ? 0.19 : 0.264)) {
            throw ValidationException::withMessages(['configuration.sticker_width' => 'The barcode is too long for this label width. Use a wider label or a shorter code.']);
        }
        $svg = $generator->getBarcode($code, $format, 1, 100);
        $svg = preg_replace('/<\?xml.*?\?>\s*/s', '', $svg);
        $svg = preg_replace('/<svg\b[^>]*>/', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-'.$quietLeft.' 0 '.($modules + $quietLeft + $quietRight).' 100" width="'.$widthMm.'mm" height="'.$heightMm.'mm" preserveAspectRatio="none" role="img" aria-label="Barcode">', $svg, 1);

        return $svg;
    }
}
