<?php

namespace App\Services;

use App\Models\BarcodeLabelPreset;
use App\Support\Expiry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BarcodePresetService
{
    public const MEASUREMENTS = ['sticker_width', 'sticker_height', 'paper_width', 'paper_height', 'row_gap', 'column_gap', 'margin_top', 'margin_bottom', 'margin_left', 'margin_right', 'padding_top', 'padding_bottom', 'padding_left', 'padding_right', 'barcode_height', 'offset_x', 'offset_y'];

    public const CONTENT = ['shop_name', 'product_name', 'price', 'barcode', 'barcode_number', 'sku', 'unit', 'expiry', 'batch', 'custom_text', 'secret_code'];

    public function defaults(): array
    {
        $c = ['sticker_width' => '49.00', 'sticker_height' => '25.00', 'paper_width' => '102.00', 'paper_height' => null, 'labels_per_row' => 2, 'row_gap' => '3.00', 'column_gap' => '2.00', 'barcode_height' => '8.00', 'alignment' => 'center', 'barcode_alignment' => 'center', 'barcode_type' => 'PRODUCT', 'orientation' => 'portrait', 'product_name_lines' => 1, 'product_name_overflow' => 'ellipsis', 'print_border' => false, 'auto_fit' => true, 'skip_labels' => 0, 'custom_text' => '', 'secret_code' => ''];
        foreach (self::MEASUREMENTS as $key) {
            $c[$key] ??= $key === 'paper_height' ? null : (str_starts_with($key, 'padding') ? '0.50' : '0.00');
        }
        foreach (self::CONTENT as $key) {
            $c['show_'.$key] = in_array($key, ['shop_name', 'product_name', 'price', 'barcode', 'barcode_number']);
            $c[$key.'_font_size'] = $key === 'price' ? 15 : ($key === 'barcode_number' ? 11 : 10);
            $c[$key.'_bold'] = in_array($key, ['shop_name', 'price', 'barcode_number']);
        }

        return $c;
    }

    public function normalize(array $input): array
    {
        $c = array_replace($this->defaults(), array_intersect_key($input, $this->defaults()));
        $rules = [];
        foreach (self::MEASUREMENTS as $key) {
            $rules[$key] = ($key === 'paper_height' ? 'nullable' : 'required').'|numeric|decimal:0,2|min:'.(str_starts_with($key, 'offset_') ? '-20' : (($key === 'barcode_height' ? '5' : (in_array($key, ['sticker_width', 'sticker_height', 'paper_width']) ? '1' : '0')))).'|max:1000';
        }
        $rules += ['labels_per_row' => 'required|integer|min:1|max:12', 'skip_labels' => 'required|integer|min:0|max:1000', 'alignment' => 'required|in:left,center,right', 'barcode_alignment' => 'required|in:left,center,right', 'barcode_type' => 'required|in:PRODUCT,CODE128,EAN13,EAN8,UPCA', 'orientation' => 'required|in:portrait,landscape', 'product_name_lines' => 'required|integer|min:1|max:2', 'product_name_overflow' => 'required|in:ellipsis,wrap,shrink', 'print_border' => 'required|boolean', 'auto_fit' => 'required|boolean', 'custom_text' => 'nullable|string|max:120', 'secret_code' => 'nullable|string|max:40'];
        foreach (self::CONTENT as $key) {
            $rules['show_'.$key] = 'required|boolean';
            $rules[$key.'_font_size'] = 'required|integer|min:6|max:32';
            $rules[$key.'_bold'] = 'required|boolean';
        }
        $c = Validator::make($c, $rules)->validate();
        foreach (self::MEASUREMENTS as $key) {
            $c[$key] = $c[$key] === null || $c[$key] === '' ? null : (string) BigDecimal::of((string) $c[$key])->toScale(2);
        }
        foreach (self::CONTENT as $key) {
            $c['show_'.$key] = (bool) $c['show_'.$key];
            $c[$key.'_bold'] = (bool) $c[$key.'_bold'];
            $c[$key.'_font_size'] = (int) $c[$key.'_font_size'];
        }
        foreach (['labels_per_row', 'skip_labels', 'product_name_lines'] as $key) {
            $c[$key] = (int) $c[$key];
        }
        $c['auto_fit'] = (bool) $c['auto_fit'];
        $c['print_border'] = (bool) $c['print_border'];
        $d = fn ($key) => BigDecimal::of($c[$key]);
        $required = $d('margin_left')->plus($d('margin_right'))->plus($d('sticker_width')->multipliedBy($c['labels_per_row']))->plus($d('column_gap')->multipliedBy($c['labels_per_row'] - 1))->plus($d('offset_x')->abs());
        if ($required->isGreaterThan($d('paper_width'))) {
            throw ValidationException::withMessages(['configuration.paper_width' => 'The configured labels exceed the paper width.']);
        }
        if ($d('padding_left')->plus($d('padding_right'))->isGreaterThanOrEqualTo($d('sticker_width')) || $d('padding_top')->plus($d('padding_bottom'))->plus($d('barcode_height'))->isGreaterThanOrEqualTo($d('sticker_height'))) {
            throw ValidationException::withMessages(['configuration.sticker_height' => 'Padding and barcode height must leave room inside the sticker.']);
        }
        if ($d('margin_left')->plus($d('offset_x'))->isNegative() || $d('margin_top')->plus($d('offset_y'))->isNegative()) {
            throw ValidationException::withMessages(['configuration.offset_x' => 'Negative calibration offsets require enough paper margin to keep labels on the page.']);
        }
        $textHeight = 0;
        $minimumTextHeight = 0;
        foreach (self::CONTENT as $key) {
            if (($key === 'unit' && ! \App\Support\Units::enabled()) || $key === 'barcode' || ! $c['show_'.$key] || (in_array($key, ['expiry', 'batch']) && ! Expiry::enabled())) {
                continue;
            }
            $lines = $key === 'product_name' ? $c['product_name_lines'] : 1;
            $textHeight += $c[$key.'_font_size'] * 1.08 * $lines * 25.4 / 96;
            $minimumTextHeight += 6 * 1.08 * $lines * 25.4 / 96;
        }
        $room = (float) $c['sticker_height'] - (float) $c['padding_top'] - (float) $c['padding_bottom'] - ($c['show_barcode'] ? (float) $c['barcode_height'] : 0);
        if (($c['auto_fit'] ? $minimumTextHeight : $textHeight) > $room) {
            throw ValidationException::withMessages(['configuration.sticker_height' => 'Some content may not fit within the current label size. Increase the sticker height or hide optional content.']);
        }
        if ($c['paper_height'] !== null && $d('margin_top')->plus($d('margin_bottom'))->plus($d('offset_y')->abs())->plus($d('sticker_height'))->isGreaterThan($d('paper_height'))) {
            throw ValidationException::withMessages(['configuration.paper_height' => 'At least one label row must fit within the paper height.']);
        }

        return $c;
    }

    public function fontSizes(array $c): array
    {
        $keys = array_filter(self::CONTENT, fn ($key) => ($key !== 'unit' || \App\Support\Units::enabled()) && $key !== 'barcode' && $c['show_'.$key] && (! in_array($key, ['expiry', 'batch']) || Expiry::enabled()));
        $height = function (float $scale) use ($keys, $c) {
            $mm = 0;
            foreach ($keys as $key) {
                $mm += max(6, $c[$key.'_font_size'] * $scale) * 1.08 * ($key === 'product_name' ? $c['product_name_lines'] : 1) * 25.4 / 96;
            }

            return $mm;
        };
        $room = (float) $c['sticker_height'] - (float) $c['padding_top'] - (float) $c['padding_bottom'] - ($c['show_barcode'] ? (float) $c['barcode_height'] : 0);
        $scale = 1;
        if ($c['auto_fit'] && $height(1) > $room) {
            $low = 0;
            $high = 1;
            for ($i = 0; $i < 24; $i++) {
                $mid = ($low + $high) / 2;
                if ($height($mid) > $room) {
                    $high = $mid;
                } else {
                    $low = $mid;
                }
            }
            $scale = $low;
        }

        return array_combine(self::CONTENT, array_map(fn ($key) => max(6, $c[$key.'_font_size'] * $scale), self::CONTENT));
    }

    public function save(array $data, ?BarcodeLabelPreset $preset = null): BarcodeLabelPreset
    {
        $configuration = $this->normalize($data['configuration']);

        return DB::transaction(function () use ($data, $preset, $configuration) {
            DB::table('document_sequences')->where('key', 'barcode-presets')->lockForUpdate()->first();
            if ($preset) {
                $preset = BarcodeLabelPreset::whereKey($preset->id)->lockForUpdate()->firstOrFail();
            }
            $default = (bool) ($data['is_default'] ?? false) || ! BarcodeLabelPreset::exists() || (bool) $preset?->is_default;
            if ($default) {
                BarcodeLabelPreset::query()->when($preset, fn ($q) => $q->where('id', '!=', $preset->id))->update(['is_default' => false]);
            }
            // Preserve hidden expiry configuration when editing with EXPIRE disabled.
            if ($preset && ! Expiry::enabled()) {
                foreach (['expiry', 'batch'] as $key) {
                    foreach (['show_'.$key, $key.'_font_size', $key.'_bold'] as $field) {
                        $configuration[$field] = $preset->configuration[$field] ?? $configuration[$field];
                    }
                }
            }
            if ($preset && ! \App\Support\Units::enabled()) {
                foreach (['show_unit', 'unit_font_size', 'unit_bold'] as $field) {
                    $configuration[$field] = $preset->configuration[$field] ?? $configuration[$field];
                }
            }
            $preset ??= new BarcodeLabelPreset(['created_by' => auth()->id()]);
            $preset->fill(['name' => $data['name'], 'configuration' => $configuration, 'is_default' => $default, 'updated_by' => auth()->id()])->save();

            return $preset;
        }, 3);
    }

    public function delete(BarcodeLabelPreset $preset): void
    {
        DB::transaction(function () use ($preset) {
            DB::table('document_sequences')->where('key', 'barcode-presets')->lockForUpdate()->first();
            $preset = BarcodeLabelPreset::whereKey($preset->id)->lockForUpdate()->firstOrFail();
            if ($preset->is_default) {
                throw ValidationException::withMessages(['preset' => 'Select another default preset before deleting this one.']);
            }
            $preset->delete();
        }, 3);
    }

    public function rowsPerPage(array $c): ?int
    {
        if (! $c['paper_height']) {
            return null;
        }
        $available = BigDecimal::of($c['paper_height'])->minus($c['margin_top'])->minus($c['margin_bottom'])->minus(BigDecimal::of($c['offset_y'])->abs())->plus($c['row_gap']);

        return max(1, (int) (string) $available->dividedBy(BigDecimal::of($c['sticker_height'])->plus($c['row_gap']), 0, RoundingMode::FLOOR));
    }
}
