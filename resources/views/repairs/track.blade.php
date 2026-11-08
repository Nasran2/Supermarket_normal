<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Track a repair · {{ $settings['business_name'] ?? config('app.name') }}</title>
@vite('resources/css/app.css')
</head>
<body class="phone-repair-theme">
<main class="repair-public repair-stack">
    <header><span class="eyebrow">{{ $settings['business_name'] ?? config('app.name') }}</span><h1>Track a repair</h1><p class="muted">Use the tracking number printed on your intake receipt.</p></header>
    <form class="card repair-panel repair-stack" method="post" action="{{ route('repairs.track') }}">@csrf
        <label class="field">Tracking number<input name="tracking" value="{{ old('tracking') }}" minlength="32" maxlength="32" pattern="[0-9a-fA-F]{32}" autocomplete="off" autocapitalize="characters" spellcheck="false" required></label>
        @error('tracking')<p class="text-danger" role="alert">Enter the 32-character tracking number from your receipt.</p>@enderror
        <div><button class="btn primary">Check status</button></div>
    </form>
    @if($result)
        <section class="card repair-panel repair-stack" aria-live="polite">
            <h2>{{ $result['status'] }}</h2><p>Estimated completion · {{ $result['due']?->format('d M Y') ?? 'To be confirmed' }}</p>
            <ul class="repair-history">@foreach($result['updates'] as $update)<li>{{ \App\Support\Repairs::STATUS[$update->public_status] }} · {{ $update->created_at->format('d M Y') }}</li>@endforeach</ul>
        </section>
    @elseif($searched)
        <p class="notice error" role="status">We couldn’t find a repair with that tracking number. Check the receipt and try again.</p>
    @endif
</main>
</body></html>
