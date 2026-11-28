@extends('developer.layout')
@section('title', 'Developer sign-in')
@section('content')
<section class="auth-card">
    <span class="eyebrow">Developer access</span><h1>Manage what’s available.</h1>
    <p class="muted">Sign in with the developer account to manage modules, reports, dashboard cards and actions for this store.</p>
    @if($errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('developer.authenticate') }}">
        @csrf
        <label class="field">Username<input name="username" value="{{ old('username') }}" autocomplete="username" maxlength="100" required autofocus></label>
        <label class="field">Password<input type="password" name="password" autocomplete="current-password" maxlength="255" required></label>
        <button type="submit" class="btn btn-primary">Sign in as developer</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:22px;margin-bottom:0">This sign-in is separate from store staff and administrator accounts.</p>
</section>
@endsection
