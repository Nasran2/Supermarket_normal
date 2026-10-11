<?php

namespace App\Http\Controllers;

use App\Services\DocumentationService;
use App\Services\ReportService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    public function index()
    {
        $guides = app(DocumentationService::class)->guides();
        $categories = array_values(array_unique(array_column($guides, 'category')));

        return view('documentation.index', compact('guides', 'categories'));
    }

    public function show(Request $request, string $slug)
    {
        $guides = app(DocumentationService::class)->guides();
        abort_unless(isset($guides[$slug]), 404);
        $guide = $guides[$slug];
        foreach ($guide['steps'] as &$step) {
            $step['frames'] = array_map(fn ($frame) => app(DocumentationService::class)->frame($frame), $step['frames']);
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
