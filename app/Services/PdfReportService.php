<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\LazyCollection;

class PdfReportService
{
    public function download(string $filename, array $data)
    {
        return response($this->render($data), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'-'.now()->format('Y-m-d').'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function render(array $data): string
    {
        $settings = app(SettingsService::class)->all();
        $logo = null;
        if (! empty($settings['logo'])) {
            $root = realpath(storage_path('app/public'));
            $path = realpath(storage_path('app/public/'.$settings['logo']));
            if ($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'])) {
                $logo = 'data:'.(str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg').';base64,'.base64_encode(file_get_contents($path));
            }
        }
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => storage_path('app/public')]);
        $pdf = new Dompdf($options);
        $landscape = count($data['headers'] ?? []) > 6;
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        if (($data['rowCount'] ?? 0) > 500) {
            return app(LargeTablePdfRenderer::class)->render($pdf, $data + ['settings' => $settings, 'logo' => $logo]);
        }
        if ($data['rows'] instanceof LazyCollection) {
            $data['rows'] = $data['rows']->collect();
        }
        if (($data['report'] ?? '') !== 'audit' && collect($data['rows'])->contains(fn ($row) => collect($row)->contains(fn ($cell) => mb_strlen((string) $cell) > 800))) {
            return app(LargeTablePdfRenderer::class)->render($pdf, $data + ['settings' => $settings, 'logo' => $logo]);
        }
        $pdf->loadHtml(view('pdf.report', $data + ['settings' => $settings, 'logo' => $logo, 'landscape' => $landscape, 'generated' => now(), 'preparedBy' => auth()->user()?->name ?? 'System', 'subtitle' => '', 'cards' => [], 'contact' => [], 'notes' => '', 'filters' => []])->render());
        $pdf->render();
        $pdf->getCanvas()->page_text($landscape ? 735 : 490, $landscape ? 571 : 817, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.35, 0.4, 0.38]);

        return $pdf->output();
    }
}
