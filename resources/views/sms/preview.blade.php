@extends('layouts.app')
@section('body-class', 'sms-workspace')
@section('title', 'Preview SMS')
@section('content')
<div class="page-heading sms-page-heading"><div><span class="eyebrow">REVIEW BEFORE SENDING</span><h1>{{ count($recipients) }} {{ Str::plural('SMS message', count($recipients)) }}</h1><p>A final look at the message each customer will receive.</p></div><span class="sms-status-pill is-pending"><x-icon name="eye" :size="14"/>Ready for review</span></div>
@if($skipped > 0)<div class="notice info"><x-icon name="info"/><span>{{ $skipped }} {{ Str::plural('customer', $skipped) }} skipped because of an invalid number or no outstanding balance.</span></div>@endif
<div class="sms-preview-grid">
@foreach($recipients as $recipient)
<article class="card sms-preview-card"><div class="sms-preview-recipient"><span class="sms-icon-tile"><x-icon name="contact" :size="20"/></span><div><span class="eyebrow">TEXT MESSAGE</span><strong>{{ $recipient['phone'] }}</strong></div><span class="sms-status-pill">{{ mb_strlen($recipient['message']) }} characters</span></div><p class="sms-preview-bubble">{{ $recipient['message'] }}</p></article>
@endforeach
</div>
<section class="card sms-preview-confirm"><div><h2>Everything look right?</h2><p>Messages will be queued for sending. Textit charges apply; longer messages may use multiple SMS segments. This preview expires in 30 minutes.</p></div><form method="POST" action="{{ route('sms.send') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><a href="{{ route('sms.index') }}" class="btn secondary">Cancel</a><button class="btn primary"><x-icon name="send" :size="17"/>Send {{ count($recipients) }} {{ Str::plural('message',count($recipients)) }}</button></form></section>
@endsection
