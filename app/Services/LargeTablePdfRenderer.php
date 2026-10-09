<?php

namespace App\Services;

use App\Support\Money;
use Dompdf\Dompdf;

/** Draws large exports directly on PDF pages without retaining an HTML table tree. */
class LargeTablePdfRenderer
{
    public function render(Dompdf $pdf, array $data): string
    {
        $pdf->loadHtml('<html><body></body></html>');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $metrics = $pdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $bold = $metrics->getFont('DejaVu Sans', 'bold');
        $width = $canvas->get_width() - 60;
        $bottom = $canvas->get_height() - 48;
        $green = [0.06, 0.42, 0.31];
        $muted = [0.38, 0.46, 0.42];
        $ink = [0.14, 0.23, 0.2];
        $settings = $data['settings'];
        $wrap = function (string $text, float $available, float $size = 8.5) use ($metrics, $font): array {
            $lines = [];
            foreach (explode("\n", $text) as $paragraph) {
                $line = '';
                foreach (preg_split('/\s+/u', $paragraph) as $word) {
                    if ($line !== '' && $metrics->getTextWidth($line.' '.$word, $font, $size) > $available) {
                        $lines[] = $line;
                        $line = '';
                    }
                    while ($metrics->getTextWidth($word, $font, $size) > $available) {
                        $part = '';
                        foreach (mb_str_split($word) as $char) {
                            if ($part !== '' && $metrics->getTextWidth($part.$char, $font, $size) > $available) {
                                break;
                            }
                            $part .= $char;
                        }
                        if ($line !== '') {
                            $lines[] = $line;
                            $line = '';
                        }
                        $lines[] = $part;
                        $word = mb_substr($word, mb_strlen($part));
                    }
                    $line .= ($line === '' ? '' : ' ').$word;
                }
                $lines[] = $line;
            }

            return $lines ?: [''];
        };
        $write = function ($x, $y, $lines, $size = 8.5, $face = null, $color = null) use ($canvas, $font, $ink) {
            foreach ($lines as $line) {
                $canvas->text($x, $y, $line, $face ?? $font, $size, $color ?? $ink);
                $y += $size + 4;
            }

            return $y;
        };
        $pageHeader = function () use ($canvas, $settings, $data, $bold, $font, $green, $muted, $ink, $width, $write, $wrap) {
            $x = 30;
            $y = 30;
            if ($data['logo']) {
                $dimensions = getimagesizefromstring(base64_decode(explode(',', $data['logo'], 2)[1]));
                $ratio = min(45 / $dimensions[0], 40 / $dimensions[1]);
                $canvas->image($data['logo'], 30, 30, $dimensions[0] * $ratio, $dimensions[1] * $ratio);
                $x = 85;
            }
            $y = $write($x, $y, $wrap($settings['business_name'] ?? 'Twinsofte', $width - 180, 17), 17, $bold, $green);
            foreach ([$settings['address'] ?? '', implode(' | ', array_filter([$settings['phone'] ?? '', $settings['email'] ?? '']))] as $line) {
                if ($line !== '') {
                    $y = $write($x, $y + 2, $wrap($line, $width - 150, 8), 8, $font, $muted);
                }
            }
            $canvas->text($width - 130, 30, now()->format('d M Y, H:i'), $font, 8, $muted);
            $write($width - 130, 44, $wrap('Prepared by '.(auth()->user()?->name ?? 'System'), 155, 8), 8, $font, $muted);
            $y = max(90, $y + 12);
            $canvas->line(30, $y, $width + 30, $y, $green, 2);
            $y = $write(30, $y + 14, $wrap($data['title'], $width, 17), 17, $bold, $ink);
            if (! empty($data['subtitle'])) {
                $y = $write(30, $y + 4, $wrap($data['subtitle'], $width, 11), 11);
            }
            $period = ($data['filters']['from'] ?? 'All history').(! empty($data['filters']['to']) ? ' to '.$data['filters']['to'] : '');
            $y = $write(30, $y + 8, $wrap('Reporting period: '.$period, $width, 8), 8, $font, $muted);
            if (! empty($data['filterLabels'])) {
                $y = $write(30, $y + 3, $wrap(collect($data['filterLabels'])->map(fn ($v, $k) => $k.': '.$v)->implode(' | '), $width, 8), 8, $font, $muted);
            }

            return $y + 12;
        };
        $headers = $data['headers'];
        $weights = array_map(fn ($h) => preg_match('/^(Product|Description|Details|Supplier|Reference|Invoice|Purchase reference|Categories)/i', $h) ? 1.6 : 1, $headers);
        $widths = array_map(fn ($weight) => $width * $weight / array_sum($weights), $weights);
        $tableHeader = function ($y) use ($canvas, $headers, $widths, $wrap, $write, $bold, $green) {
            $wrapped = [];
            foreach ($headers as $i => $header) {
                $wrapped[] = $wrap($header, $widths[$i] - 12, 8);
            }
            $height = max(array_map('count', $wrapped)) * 12 + 14;
            $x = 30;
            foreach ($wrapped as $i => $lines) {
                $canvas->filled_rectangle($x, $y, $widths[$i], $height, $green);
                $write($x + 6, $y + 7, $lines, 8, $bold, [1, 1, 1]);
                $x += $widths[$i];
            }

            return $y + $height;
        };
        $y = $pageHeader();
        foreach (array_chunk($data['cards'] ?? [], 4, true) as $group) {
            $cardWidth = $width / count($group);
            $x = 30;
            foreach ($group as $label => $amount) {
                $canvas->filled_rectangle($x, $y, $cardWidth - 4, 65, [0.94, 0.97, 0.95]);
                $write($x + 8, $y + 8, $wrap($label, $cardWidth - 16, 8), 8, $font, $muted);
                $write($x + 8, $y + 40, $wrap(($settings['currency_symbol'] ?? 'Rs.').' '.Money::display($amount), $cardWidth - 16, 11), 11, $bold, $green);
                $x += $cardWidth;
            }
            $y += 75;
        }
        if (! empty($data['contact'])) {
            $y = $write(30, $y, $wrap(collect($data['contact'])->filter()->map(fn ($v, $k) => $k.': '.$v)->implode(' | '), $width, 9), 9) + 8;
        }
        if (($data['report'] ?? '') === 'audit') {
            foreach ($data['rows'] as $row) {
                foreach ([$row[0].' | '.$row[1].' | '.$row[2].' | '.$row[3], 'Before: '.$row[4], 'After: '.$row[5]] as $block) {
                    foreach ($wrap($block, $width, 8.5) as $line) {
                        if ($y + 13 > $bottom) {
                            $canvas->new_page();
                            $y = $pageHeader();
                        }
                        $canvas->text(30, $y, $line, $font, 8.5, $ink);
                        $y += 13;
                    }
                    $y += 5;
                }
                $canvas->line(30, $y, $width + 30, $y, [0.85, 0.9, 0.87], 0.5);
                $y += 14;
            }
        } else {
            $y = $tableHeader($y);
            $stripe = false;
            foreach ($data['rows'] as $row) {
                $wrapped = [];
                foreach ($row as $i => $cell) {
                    $wrapped[] = $wrap((string) ($cell ?? '-'), $widths[$i] - 12);
                }
                $lineCount = max(array_map('count', $wrapped));
                $offset = 0;
                while ($offset < $lineCount) {
                    $room = (int) floor(($bottom - $y - 14) / 12.5);
                    if ($room < 1) {
                        $canvas->new_page();
                        $y = $tableHeader($pageHeader());
                        $room = (int) floor(($bottom - $y - 14) / 12.5);
                    }
                    // Keep a row together when it fits on a fresh page.
                    if ($offset === 0 && $lineCount > $room && $lineCount < 20) {
                        $canvas->new_page();
                        $y = $tableHeader($pageHeader());
                        $room = (int) floor(($bottom - $y - 14) / 12.5);
                    }
                    $take = min($room, $lineCount - $offset);
                    $height = $take * 12.5 + 14;
                    $x = 30;
                    foreach ($wrapped as $i => $lines) {
                        if ($stripe) {
                            $canvas->filled_rectangle($x, $y, $widths[$i], $height, [0.965, 0.977, 0.97]);
                        }
                        $cellLines = array_slice($lines, $offset, $take);
                        foreach ($cellLines as $n => $line) {
                            $right = is_numeric(str_replace(',', '', (string) $row[$i]));
                            $tx = $right ? $x + $widths[$i] - 6 - $metrics->getTextWidth($line, $font, 8.5) : $x + 6;
                            $canvas->text($tx, $y + 7 + $n * 12.5, $line, $font, 8.5, $ink);
                        }
                        $x += $widths[$i];
                    }
                    $y += $height;
                    $canvas->line(30, $y, $width + 30, $y, [0.86, 0.9, 0.88], 0.5);
                    $offset += $take;
                }
                $stripe = ! $stripe;
            }
        }
        foreach ($wrap($data['notes'] ?? '', $width, 8) as $line) {
            if ($y + 12 > $bottom) {
                $canvas->new_page();
                $y = $pageHeader();
            }
            $canvas->text(30, $y + 6, $line, $font, 8, $muted);
            $y += 12;
        }
        $canvas->page_text(30, $canvas->get_height() - 28, ($settings['business_name'] ?? 'Twinsofte').' | '.$data['title'].' | '.($settings['currency'] ?? 'LKR'), $font, 8, $muted);
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $muted);

        return $pdf->output();
    }
}
