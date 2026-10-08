@extends('layouts.app')
@section('title',$record->exists?'Edit customer':'Add customer')
@section('content')
<div class="page-heading"><div><span class="eyebrow">CUSTOMER ACCOUNTS</span><h1>{{ $record->exists?'Edit customer':'Add customer' }}</h1><p>Keep contact details and the amount already owed in one place.</p></div><a class="btn secondary" href="{{ route('manage.index','customers') }}"><x-icon name="arrow-left"/>Customers</a></div>
<form class="customer-editor" method="POST" action="{{ $record->exists?route('manage.update',['customers',$record->id]):route('manage.store','customers') }}">
    @csrf
    @if($record->exists)
        @method('PUT')
    @endif
    <section class="card customer-info-form">
        <div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="contact"/></span><div><h2>Customer information</h2><p>Only the customer name is required.</p></div></div>
        <div class="customer-edit-fields">
            <label class="field full"><span>Customer name <span class="required">*</span></span><input name="name" value="{{ old('name',$record->name) }}" maxlength="255" autocomplete="name" placeholder="Enter full name" required autofocus>@error('name')<small class="text-danger">{{ $message }}</small>@enderror</label>
            <label class="field"><span>Phone <small>optional</small></span><input name="phone" type="tel" value="{{ old('phone',$record->phone) }}" maxlength="255" autocomplete="tel" placeholder="Phone number">@error('phone')<small class="text-danger">{{ $message }}</small>@enderror</label>
            <label class="field"><span>Email <small>optional</small></span><input name="email" type="email" value="{{ old('email',$record->email) }}" maxlength="255" autocomplete="email" placeholder="Email address">@error('email')<small class="text-danger">{{ $message }}</small>@enderror</label>
            <label class="field full"><span>Address <small>optional</small></span><textarea name="address" rows="3" maxlength="2000" autocomplete="street-address" placeholder="Customer address">{{ old('address',$record->address) }}</textarea>@error('address')<small class="text-danger">{{ $message }}</small>@enderror</label>
        </div>
        <label class="customer-active-setting"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active',$record->exists?$record->active:true))><span><strong>Active customer</strong><small>Available for selection at the POS.</small></span></label>
    </section>
    <aside class="card customer-opening-card">
        <span class="customer-due-icon"><x-icon name="wallet" :size="25"/></span>
        <span class="eyebrow">BALANCE BROUGHT FORWARD</span><h2>Opening due</h2><p>Enter the amount this customer already owes from earlier purchases.</p>
        <label class="field"><span>Old balance (due)</span><div class="customer-money-field"><span>{{ $settings['currency_symbol']??'Rs.' }}</span><input name="opening_due" type="number" min="0" max="999999999" step="0.01" inputmode="decimal" value="{{ old('opening_due',$record->opening_due??'0.00') }}" aria-label="Old balance (due)"></div><small>Leave 0 if the customer has no outstanding balance.</small>@error('opening_due')<small class="text-danger">{{ $message }}</small>@enderror</label>
        <div class="customer-due-explanation"><x-icon name="info" :size="17"/><span>Saved as an outstanding due on the customer account. Today's POS bill is calculated separately.</span></div>
    </aside>
    <div class="customer-editor-footer"><a class="btn secondary" href="{{ route('manage.index','customers') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>{{ $record->exists?'Save customer':'Create customer' }}</button></div>
</form>
@endsection
