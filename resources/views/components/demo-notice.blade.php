@if($demoMode ?? false)
<div class="demo-notice" role="status">
    <strong>DEMO MODE</strong>
    <span>Try the system with sample data. Changes reset every 10 days.@if($demoNextReset ?? null) Next reset: {{ $demoNextReset->copy()->timezone(config('app.timezone'))->format('d M Y, h:i A') }}.@endif</span>
</div>
@endif
