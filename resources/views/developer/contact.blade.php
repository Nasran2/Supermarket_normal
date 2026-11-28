<dl class="contact-grid">
    @if($contact['name'])<div><dt>Developer</dt><dd>{{ $contact['name'] }}</dd></div>@endif
    @if($contact['phone'])<div><dt>Phone</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></dd></div>@endif
    @if($contact['website'])<div><dt>Website</dt><dd>@if($contact['url'])<a href="{{ $contact['url'] }}" target="_blank" rel="noopener noreferrer">{{ $contact['website'] }}</a>@else{{ $contact['website'] }}@endif</dd></div>@endif
</dl>
@if(!$contact['phone'] && !$contact['website'])<p class="muted">Ask your administrator for the developer’s contact details.</p>@endif
