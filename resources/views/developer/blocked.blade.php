@extends('developer.layout')
@section('title', 'Developer permission required')
@section('content')
<section class="auth-card" role="alert">
    <span class="eyebrow">Access unavailable</span><h1>Developer permission required</h1>
    <p>{{ $message }}</p>
    @include('developer.contact')
    <a class="btn btn-primary" href="{{ route('login') }}">Return to store</a>
</section>
@endsection
