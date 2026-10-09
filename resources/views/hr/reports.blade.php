@extends('layouts.app')
@section('title','HR reports')
@section('content')
<div class="page-heading"><div><span class="eyebrow">HUMAN RESOURCES</span><h1>HR reports</h1><p>Filter staff history and download company-branded PDF and CSV reports.</p></div></div>@include('hr.partials.nav')@include('hr.partials.report-cards')
@endsection
