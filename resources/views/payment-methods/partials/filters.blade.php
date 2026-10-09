<section class="card payment-date-filters">
    <div class="payment-range-heading"><div><h2>Payment activity</h2><p class="muted">{{ \Carbon\Carbon::parse($filters['from'])->format('d M Y') }} — {{ \Carbon\Carbon::parse($filters['to'])->format('d M Y') }}</p></div><span class="badge green">{{ $filters['range']==='custom'?'Custom range':$ranges[$filters['range']] }}</span></div>
    <nav class="payment-quick-ranges" aria-label="Quick date ranges">
        @foreach($ranges as $key=>$label)
        <a class="payment-range {{ $filters['range']===$key?'selected':'' }}" href="{{ request()->url().'?'.http_build_query(['range'=>$key]+array_intersect_key($filters,array_flip(['q','active']))) }}" @if($filters['range']===$key) aria-current="date" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" class="payment-custom-range">
        <input type="hidden" name="range" value="custom">
        <label class="field compact"><span>From date</span><input type="date" name="from" value="{{ $filters['from'] }}" required></label>
        <label class="field compact"><span>To date</span><input type="date" name="to" value="{{ $filters['to'] }}" required></label>
        @if($overview??false)
        <label class="field compact payment-method-search"><span>Find a payment method</span><input type="search" name="q" value="{{ $filters['q']??'' }}" placeholder="Name or code"></label>
        <label class="field compact"><span>Status</span><select name="active"><option value="">All methods</option><option value="1" @selected(($filters['active']??'')==='1')>Active</option><option value="0" @selected(($filters['active']??'')==='0')>Inactive</option></select></label>
        @endif
        <button class="btn primary" type="submit"><x-icon name="sliders-horizontal"/>Apply filters</button>
        <a class="text-link" href="{{ request()->url() }}">Reset to today</a>
    </form>
</section>
