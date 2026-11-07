@extends('superadmin.layout')

@section('title', 'Privacy Mode')

@push('head')
<style>
    .privacy-form { display: grid; gap: 16px; padding: 0 24px 24px; }
    .privacy-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    @media (max-width: 760px) { .privacy-options { grid-template-columns: 1fr; } }
    .privacy-option { margin: 0; padding: 18px; border: 1px solid var(--line, #e2e8f0); border-radius: 12px; min-width: 0; }
    .privacy-option h3, .privacy-areas legend { font-weight: 700; }
    .privacy-option p { margin: 5px 0 12px; }
    .privacy-option kbd { display: inline-block; border: 1px solid var(--line); border-radius: 5px; padding: 2px 6px; font-size: .8rem; }
    .privacy-count { max-width: 400px; }
    .privacy-areas { border: 0; padding: 0; margin: 8px 0; min-width: 0; }
    .privacy-areas legend { padding: 0; margin-bottom: 5px; }
    .privacy-areas p { margin: 0 0 12px; }
    .privacy-areas .switch-wrap { background: var(--surface, #f8fafc); }
    .privacy-form .toggle-switch:focus-within { outline: 2px solid #4f46e5; outline-offset: 3px; border-radius: 24px; }
    .privacy-form .sticky-action-bar { margin-top: 0; }
</style>
@endpush

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Choose which sales to show and where the selected view applies.</p>
    </div>
</div>

<section class="card">
    <div class="card-heading">
        <div>
            <h2>Sales privacy shortcuts</h2>
            <p>Show the highest or lowest bills by customer payable total, including payment fees.</p>
        </div>
        <span class="badge {{ $privacy['enabled'] ? 'green' : 'slate' }}">{{ $privacy['enabled'] ? 'Enabled' : 'Disabled' }}</span>
    </div>

    <form class="privacy-form" method="POST" action="{{ route('superadmin.privacy-mode.save') }}">
        @csrf
        <input type="hidden" name="enabled" value="0">
        <label class="switch-wrap">
            <span class="switch-info">
                <strong>Enable filtered sales views</strong>
                <small>Staff with Sales List access can switch views for their current sign-in.</small>
            </span>
            <span class="toggle-switch">
                <input type="checkbox" name="enabled" value="1" role="switch" @checked(old('enabled', $privacy['enabled']))>
                <span class="toggle-slider" aria-hidden="true"></span>
            </span>
        </label>

        <label class="field privacy-count">Number of bills to show
            <input name="bill_count" type="number" min="1" max="1000" required value="{{ old('bill_count', $privacy['bill_count']) }}">
            <small>Both views use the same count. If fewer bills match, the view shows all matching active bills.</small>
        </label>

        <div class="privacy-options">
            <div class="privacy-option">
                <h3>Highest amounts</h3>
                <p class="muted text-sm">Start with the largest bills and show them from highest to lowest.</p>
                <kbd>⌘ + D</kbd> <span class="muted text-sm">Mac</span>
                <kbd>Ctrl + D</kbd> <span class="muted text-sm">Windows</span>
            </div>
            <div class="privacy-option">
                <h3>Lowest amounts</h3>
                <p class="muted text-sm">Start with the smallest bills and show them from lowest to highest.</p>
                <kbd>⌘ + X</kbd> <span class="muted text-sm">Mac</span>
                <kbd>Ctrl + X</kbd> <span class="muted text-sm">Windows</span>
            </div>
        </div>

        <fieldset class="privacy-areas">
            <legend>Where Privacy Mode applies</legend>
            <p class="muted text-sm">Select where the current view is available. Reports include Sales, Product sales, Profit &amp; loss, Payment methods, processing charges, Sales returns and Due collections.</p>
            <input type="hidden" name="surfaces_submitted" value="1">
            <div class="privacy-options">
                @foreach(\App\Support\SalesPrivacy::SURFACES as $surface => [$label, $description])
                    <label class="switch-wrap">
                        <span class="switch-info">
                            <strong>{{ $label }}</strong>
                            <small>{{ $description }}</small>
                        </span>
                        <span class="toggle-switch">
                            <input type="checkbox" name="applies_to[]" value="{{ $surface }}" role="switch"
                                @checked(in_array($surface, (array) old('applies_to', old('surfaces_submitted') ? [] : $privacy['applies_to']), true))>
                            <span class="toggle-slider" aria-hidden="true"></span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="sticky-action-bar">
            <span>Choose All sales on any enabled screen to clear the current view.</span>
            <button class="btn primary" type="submit">Save privacy settings</button>
        </div>
    </form>
</section>

<section class="card" style="margin-top: 18px;">
    <div class="card-heading">
        <div>
            <h2>New invoice numbers</h2>
            <p>A shared counter starts at 01 and increases across dates and prefix changes.</p>
        </div>
        <span class="secret-badge">{{ config('features.scode') ? 'Encoded date' : 'Random letters' }}</span>
    </div>
    <p class="muted text-sm" style="padding: 0 24px 24px;">
        @if(config('features.scode'))
            With <code>SCODE=true</code>, the invoice date is encoded using the configured digit-to-letter mapping.
        @else
            With <code>SCODE=false</code>, each new invoice uses eight random English letters.
        @endif
        Example <code>INV-{{ config('features.scode') ? app(\App\Services\SecretCodeService::class)->encodeDigits(now()->format('Ymd')) : 'QKNRWZPX' }}01</code>.
        The format is applied when a sale is issued.
    </p>
</section>
@endsection
