@extends('installer.layout')

@section('content')
<div class="intro">
    <h1>Twin POS is installed</h1>
    <p>Your database and administrator account are ready. Sign in with the username and password you created.</p>
    <div class="actions"><a class="button" href="{{ route('login') }}">Go to POS sign-in</a></div>
</div>
@endsection
