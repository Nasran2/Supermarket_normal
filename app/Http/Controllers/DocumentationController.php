<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    public function index()
    {
        $guides = config('documentation');
        $categories = array_values(array_unique(array_column($guides, 'category')));

        return view('documentation.index', compact('guides', 'categories'));
    }

    public function show(Request $request, string $slug)
    {
        $guides = config('documentation');
        abort_unless(isset($guides[$slug]), 404);
        $guide = $guides[$slug];
        $walkthrough = config('documentation-walkthroughs');
        foreach ($guide['steps'] as $index => &$step) {
            $step['frames'] = array_map(function ($frame) use ($walkthrough) {
                $screen = $walkthrough['screens'][$frame['screen']];
                $box = $frame['target'] ? $screen['targets'][$frame['target']] : null;
                if ($box) {
                    // Keep a partially visible table inside the captured viewport.
                    $box[2] = min($box[2], 100 - $box[0]);
                    $box[3] = min($box[3], 100 - $box[1]);
                }
                $scale = $box ? min(2.4, max(1.35, 65 / max($box[2], 20))) : 1;
                $x = $box ? max(50 / $scale, min(100 - 50 / $scale, $box[0] + min($box[2] / 2, 12))) : 50;
                $y = $box ? max(50 / $scale, min(100 - 50 / $scale, $box[1] + $box[3] / 2)) : 50;

                return $frame + ['width' => $screen['width'], 'height' => $screen['height'], 'box' => $box, 'scale' => $scale, 'panX' => 50 - $x * $scale, 'panY' => 50 - $y * $scale];
            }, $walkthrough['guides'][$slug][$index]);
        }
        unset($step);
        $related = array_filter($guides, fn ($other, $key) => $key !== $slug && $other['category'] === $guide['category'], ARRAY_FILTER_USE_BOTH);
        $screenUrl = null;
        if (isset($guide['link'])) {
            $link = $guide['link'];
            $allowed = $link['permission'] ? collect((array) $link['permission'])->every(fn ($permission) => $request->user()->hasPermission($permission)) : collect(array_keys(ReportService::TITLES))->contains(fn ($kind) => $request->user()->hasPermission(ReportService::permission($kind)));
            if (isset($link['setting'])) {
                $allowed = $allowed && app(SettingsService::class)->get($link['setting'], true);
            }
            if ($allowed) {
                $screenUrl = route($link['route'], $link['parameters']);
            }
        }

        return view('documentation.show', compact('guide', 'slug', 'related', 'screenUrl'));
    }
}
