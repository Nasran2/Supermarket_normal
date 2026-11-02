<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\Demo;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;

class TaxInvoiceService
{
    public function calculate(string $amount, ?array $existing = null): ?array
    {
        if (! $existing && ! config('features.customer_tax')) {
            return null;
        }
        $settings = app(SettingsService::class);
        $rate = (string) ($existing['rate'] ?? $settings->get('vat_rate', 18));
        $mode = $existing['mode'] ?? $settings->get('vat_price_mode', 'INCLUSIVE');
        $base = $mode === 'EXCLUSIVE' ? $amount : (string) BigDecimal::of($amount)->multipliedBy(100)->dividedBy(BigDecimal::of(100)->plus($rate), 2, RoundingMode::HALF_UP);
        $vat = $mode === 'EXCLUSIVE' ? Money::percent($base, $rate) : Money::sub($amount, $base);

        return ['rate' => $rate, 'mode' => $mode, 'base' => $base, 'vat' => $vat, 'total' => Money::add($base, $vat)];
    }

    public function snapshot(Sale $sale, array $purchaser, array $delivery): array
    {
        $sale->loadMissing('items', 'payments');
        $settings = app(SettingsService::class)->all();
        $tax = $sale->tax;
        $lineTotal = Money::sum($sale->items->pluck('total'));
        $cumulative = '0.00';
        $allocated = '0.00';
        $lines = [];
        foreach ($sale->items as $item) {
            $cumulative = Money::add($cumulative, $item->total);
            $target = Money::compare($lineTotal, 0) > 0 ? (string) BigDecimal::of($tax['base'])->multipliedBy($cumulative)->dividedBy($lineTotal, 2, RoundingMode::HALF_UP) : '0.00';
            $amount = Money::sub($target, $allocated);
            $allocated = $target;
            $lines[] = ['reference' => $item->sku, 'description' => $item->name, 'quantity' => rtrim(rtrim($item->quantity, '0'), '.'), 'unit' => $item->unit,
                'price' => (string) BigDecimal::of($amount)->dividedBy($item->quantity, 4, RoundingMode::HALF_UP), 'amount' => $amount];
        }

        return ['note' => $settings['tax_invoice_note'] ?? '', 'invoice' => $sale->invoice, 'date' => $sale->sold_at->format('d/m/Y'), 'supplier' => ['tax_number' => $settings['tax_number'], 'name' => trim($settings['legal_name'] ?? '') ?: $settings['business_name'], 'address' => trim(($settings['address'] ?? '').' '.($settings['city'] ?? '')), 'phone' => $settings['phone']],
            'purchaser' => $purchaser, 'delivery' => $delivery, 'lines' => $lines, 'tax' => $tax,
            'fees' => Money::sub($sale->customer_payable, $sale->sale_amount), 'payable' => $sale->customer_payable,
            'words' => $this->words($sale->customer_payable, $settings['currency'] ?? 'LKR'), 'payment' => $sale->payment_names ?: 'On account', 'currency' => $settings['currency'] ?? 'LKR'];
    }

    private function words(string $amount, string $currency): string
    {
        [$whole, $cents] = explode('.', Money::round($amount));
        $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);

        return ucfirst($formatter->format((int) $whole)).' '.$currency.' and '.$formatter->format((int) $cents).' cents only';
    }

    public function render(array $invoice): string
    {
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('pdf.tax-invoice', compact('invoice'))->render());
        $pdf->render();
        $pdf->getCanvas()->page_text(470, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
        Demo::watermarkPdf($pdf);

        return $pdf->output();
    }
}
