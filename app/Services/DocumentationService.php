<?php

namespace App\Services;

use App\Support\Expiry;

class DocumentationService
{
    public function languages(): array
    {
        return array_filter(['ta' => config('documentation-languages.tamil'), 'si' => config('documentation-languages.sinhala')]);
    }

    public function copies(string $text, array $replace = []): array
    {
        $tokens = [];
        foreach ($replace as $key => $value) {
            $tokens[':'.$key] = $value;
        }
        $copies = ['en' => strtr($text, $tokens)];
        foreach ($this->languages() as $language => $enabled) {
            $translated = config('documentation-translations.'.$language)[$text] ?? null;
            if ($translated) {
                $copies[$language] = strtr($translated, $tokens);
            }
        }

        return $copies;
    }

    public function labels(): array
    {
        return collect(['play' => 'Play example', 'pause' => 'Pause example', 'step' => 'Step :current of :total', 'screen' => 'Screen :current of :total', 'overview' => 'Show full screen', 'zoom' => 'Zoom to control', 'guide' => ':count guide', 'guides' => ':count guides'])->map(fn ($text) => $this->copies($text))->all();
    }

    public function guides(): array
    {
        $guides = [];
        $walkthroughs = config('documentation-walkthroughs.guides');
        foreach (config('documentation') as $slug => $guide) {
            if (! empty($guide['requires_expiry']) && ! Expiry::enabled()) {
                continue;
            }
            $steps = [];
            foreach ($guide['steps'] as $index => $step) {
                if (! empty($step['requires_expiry']) && ! Expiry::enabled()) {
                    continue;
                }
                $step['frames'] = $walkthroughs[$slug][$index];
                $steps[] = $step;
            }
            if (! \App\Support\Units::enabled()) {
                // Existing walkthrough screenshots include the unit sidebar and controls.
                // Present the complete written guide without exposing those stale screens.
                if ($slug === 'units-and-categories') {
                    $guide['title'] = 'Organize product categories';
                    $guide['description'] = 'Group products into categories for browsing and reporting.';
                    $guide['path'] = 'Categories';
                    $guide['tip'] = 'Choose clear category names so staff can find products quickly.';
                    $steps = array_slice($steps, 0, 1);
                    $guide['link'] = ['permission' => 'categories.view', 'route' => 'manage.index', 'parameters' => ['resource' => 'categories']];
                }
                $guide = array_replace_recursive($guide, config('documentation-quantity.'.$slug, []));
                $steps = array_replace_recursive($steps, config('documentation-quantity.'.$slug.'.steps', []));
                foreach ($steps as &$step) {
                    $step['frames'] = [];
                }
                unset($step);
            }
            $guide['screens_enabled'] = \App\Support\Units::enabled();
            $guide['steps'] = $steps;
            $guide['search'] = mb_strtolower(implode(' ', array_merge(...array_map(fn ($text) => array_values($this->copies($text)), [$guide['title'], $guide['description'], $guide['category'], $guide['path'], ...array_column($steps, 'title'), ...array_column($steps, 'text')]))));
            $guides[$slug] = $guide;
        }

        return $guides;
    }

    public function frame(array $frame): array
    {
        $screen = config('documentation-walkthroughs.screens')[$frame['screen']];
        $path = public_path('help-assets/screens/'.$frame['screen'].'.png');
        [$width, $height] = getimagesize($path);
        $box = $frame['target'] ? $screen['targets'][$frame['target']] : null;
        if ($box) {
            $box[2] = min($box[2], 100 - $box[0]);
            $box[3] = min($box[3], 100 - $box[1]);
        }
        $scale = $box ? min(2.4, max(1.35, 65 / max($box[2], 20))) : 1;
        $x = $box ? max(50 / $scale, min(100 - 50 / $scale, $box[0] + min($box[2] / 2, 12))) : 50;
        $y = $box ? max(50 / $scale, min(100 - 50 / $scale, $box[1] + $box[3] / 2)) : 50;

        // Version the image together with its coordinates, so browser caches
        // cannot pair a newly measured highlight with an older screenshot.
        $version = substr(hash_file('sha256', $path), 0, 12);

        return $frame + ['width' => $width, 'height' => $height, 'box' => $box, 'scale' => $scale, 'panX' => 50 - $x * $scale, 'panY' => 50 - $y * $scale, 'url' => asset('help-assets/screens/'.$frame['screen'].'.png').'?v='.$version];
    }
}
