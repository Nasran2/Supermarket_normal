<?php

namespace App\Support;

use Dompdf\Dompdf;
use Illuminate\Http\Request;

class Demo
{
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled', false);
    }

    public static function protectedPage(Request $request): bool
    {
        return $request->routeIs('settings.*', 'barcode.settings*', 'barcode.presets.*', 'profile*', 'units.default', 'hr.setup*', 'hr.staff.create', 'hr.staff.edit', 'hr.staff.store', 'hr.staff.update', 'hr.staff.employment')
            || ($request->routeIs('manage.*') && in_array($request->route('resource'), ['users', 'roles', 'payment-methods', 'payment-rules', 'units', 'unit-presets'], true));
    }

    public static function readOnlyForm(Request $request): bool
    {
        return self::protectedPage($request) && $request->routeIs('settings.edit', 'barcode.settings', 'barcode.presets.edit', 'barcode.presets.create', 'profile', 'manage.create', 'manage.edit', 'hr.setup', 'hr.staff.create', 'hr.staff.edit');
    }

    public static function watermarkPdf(Dompdf $pdf): void
    {
        if (! self::enabled()) {
            return;
        }
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'bold');
        $pdf->getCanvas()->page_script(function ($page, $pages, $canvas, $metrics) use ($font) {
            $size = min($canvas->get_width(), $canvas->get_height()) * 0.2;
            $width = $metrics->getTextWidth('DEMO', $font, $size);
            $canvas->save();
            $canvas->set_opacity(0.10);
            $canvas->rotate(-30, $canvas->get_width() / 2, $canvas->get_height() / 2);
            $canvas->text(($canvas->get_width() - $width) / 2, ($canvas->get_height() - $size) / 2, 'DEMO', $font, $size, [0.20, 0.42, 0.35]);
            $canvas->restore();
            $canvas->set_opacity(1);
            $canvas->text(30, $canvas->get_height() - 40, 'DEMO - Sample document', $font, 8, [0.40, 0.50, 0.46]);
        });
    }
}
