<div class="center bill-business">
    @php($billLogos = collect(['logo', 'logo_secondary'])->filter(fn ($key) => ($settings[config('bill_fields.business.'.$key.'.setting')] ?? true) && filled($settings[$key] ?? null)))
    @if($billLogos->isNotEmpty())
        <div class="bill-logo-row">
            @foreach($billLogos as $key)
                <img class="logo" src="{{ asset('storage/'.$settings[$key]) }}" alt="{{ $key==='logo_secondary'?'Second bill logo':'Business logo' }}">
            @endforeach
        </div>
    @endif
    @foreach(config('bill_fields.business') as $field => $option)
        @if(!in_array($field, ['logo', 'logo_secondary', 'currency', 'currency_symbol']) && ($settings[$option['setting']] ?? $option['default']) && filled($settings[$field] ?? null))
            @if($field === 'business_name')
                <h1>{{ $settings[$field] }}</h1>
            @else
                <p>@if(in_array($field, ['phone', 'mobile', 'whatsapp', 'email', 'website', 'registration_number', 'tax_number'])){{ match($field) { 'phone' => 'Telephone', 'whatsapp' => 'WhatsApp', 'registration_number' => 'Reg. No.', 'tax_number' => 'Tax / VAT', default => ucfirst($field) } }}: @endif{{ $settings[$field] }}</p>
            @endif
        @endif
    @endforeach
</div>
