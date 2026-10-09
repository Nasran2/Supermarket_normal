<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title><style>
@page { margin: 30px 30px 46px; }
body { font-family: DejaVu Sans, sans-serif; color: #243b33; font-size: 9px; line-height: 1.45; }
.letterhead { width:100%; border-bottom:3px solid #087c5d; margin-bottom:18px; padding-bottom:14px; }
.brand { font-size:21px; font-weight:bold; color:#075f48; } .logo { max-width:65px; max-height:55px; margin-right:14px; } .meta { text-align:right; color:#657970; font-size:8px; }
h1 { font-size:20px; margin:0 0 3px; } .subtitle { color:#526c60; font-size:12px; margin:0 0 12px; }
.context { background:#f0f7f3; border:1px solid #d4e5dc; padding:9px 12px; margin-bottom:14px; } .context span { margin-right:14px; }
.contact { margin-bottom:12px; } .contact strong { color:#075f48; } .cards { width:100%; margin:12px 0 18px; border-collapse:collapse; } .cards td { background:#f4f8f6; border:1px solid #dbe8e0; padding:10px; vertical-align:top; } .cards strong { display:block; font-size:14px; color:#075f48; margin-top:5px; }
.data { width:100%; border-collapse:collapse; table-layout:fixed; font-size:{{ count($headers)>10?'7.5':'9' }}px; } .data thead { display:table-header-group; } .data th { color:#fff; background:#126b51; text-align:left; padding:8px 6px; line-height:1.35; } .data td { padding:8px 6px; border-bottom:1px solid #e2e9e5; vertical-align:top; overflow-wrap:break-word; word-wrap:break-word; } .data tr:nth-child(even) td { background:#f6f9f7; } .data tr { page-break-inside:avoid; } .number { text-align:right; } .empty { padding:24px; color:#657970; text-align:center; }
.notes { font-size:8px; margin-top:16px; padding-top:10px; border-top:1px solid #dbe8e0; color:#61776a; } .footer { position:fixed; bottom:-28px; left:0; right:0; border-top:1px solid #dbe8e0; padding-top:6px; font-size:8px; color:#657970; } .audit-entry { margin-bottom:18px; } .audit-entry h2 { font-size:12px; padding:8px; background:#eef5f1; } .audit-entry pre { white-space:pre-wrap; word-wrap:break-word; font-family:DejaVu Sans, sans-serif; font-size:8px; }
</style></head><body>
<table class="letterhead"><tr><td>@if($logo)<img class="logo" src="{{ $logo }}" alt="Logo">@endif
<div class="brand">{{ $settings['business_name']??'Twinsofte' }}</div>@if(!empty($settings['address']))<div>{{ $settings['address'] }}</div>@endif
<div>@if(!empty($settings['phone']))Tel: {{ $settings['phone'] }}@endif @if(!empty($settings['email'])) | {{ $settings['email'] }}@endif
</div></td><td class="meta">{{ $settings['currency']??'LKR' }}<br>Generated {{ $generated->format('d M Y, h:i A') }}<br>Prepared by {{ $preparedBy }}</td></tr></table>
<h1>{{ $title }}</h1>@if($subtitle)<p class="subtitle">{{ $subtitle }}</p>@endif
<div class="context"><strong>Reporting period:</strong> {{ $filters['from']??'All history' }}@if(!empty($filters['to'])) to {{ $filters['to'] }}@endif
<br>@foreach($filterLabels??[] as $label=>$value)<span><strong>{{ $label }}:</strong> {{ $value }}</span>@endforeach
</div>
@if($contact)<div class="contact">@foreach($contact as $label=>$value)@if($value)<div><strong>{{ $label }}:</strong> {{ $value }}</div>@endif @endforeach
</div>@endif
@if($cards)<table class="cards">@foreach(array_chunk($cards,4,true) as $group)<tr>@foreach($group as $label=>$amount)<td>{{ $label }}<strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($amount) }}</strong></td>@endforeach
</tr>@endforeach
</table>@endif
@if(($report??'')==='audit')
@forelse($rows as $row)<section class="audit-entry"><h2>{{ $row[0] }} | {{ $row[1] }} | {{ $row[2] }} | {{ $row[3] }}</h2><strong>Before</strong><pre>{{ $row[4] }}</pre><strong>After</strong><pre>{{ $row[5] }}</pre></section>@empty
<p class="empty">No audit entries in this reporting period.</p>@endforelse
@else
<table class="data"><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach
</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach($row as $cell)<td class="{{ is_numeric(str_replace(',','',(string)$cell))?'number':'' }}">{{ $cell??'-' }}</td>@endforeach
</tr>@empty
<tr><td colspan="{{ count($headers) }}" class="empty">No records match these filters.</td></tr>@endforelse
</tbody></table>
@endif
@if($notes)<p class="notes">{{ $notes }}</p>@endif
<div class="footer">{{ $settings['business_name']??'Twinsofte' }} | {{ $title }} | {{ $settings['currency']??'LKR' }}</div>
</body></html>
