@extends('layouts.app')
@section('title',($record->exists?'Edit ':'Add ').($resource==='roles'?'role':\Illuminate\Support\Str::singular($def['title'])))
@section('content')
@php
$singular = $resource==='roles'?'role':\Illuminate\Support\Str::singular(strtolower($def['title']));
$icon = match($resource) { 'users'=>'users','roles'=>'shield-check','units'=>'ruler','suppliers'=>'truck','expenses'=>'wallet','categories'=>'tags','payment-methods'=>'credit-card','payment-rules'=>'percent',default=>'folder-open' };
$intro = match($resource) { 'users'=>'Give your team the right access to your store.', 'roles'=>'Choose exactly what this role can see and manage.', 'units'=>'Define how products are counted, weighed or measured.', 'suppliers'=>'Keep supplier details ready for your next purchase.', 'expenses'=>'Record spending with a clear category and payment method.',default=>'Keep your store information organised and up to date.' };
$help = match($resource) { 'users'=>'Choose a role that matches this team member’s responsibilities. Only active accounts can sign in.', 'roles'=>'Select only the permissions needed for this role. Existing team members inherit changes when you save.', 'units'=>'Enable decimals for weight, length and volume. Use whole quantities for pieces, boxes and packs.', 'suppliers'=>'A phone number and address make ordering and follow-ups easier.', 'expenses'=>'Cash expenses are recorded against your open register. Card and transfer expenses stay in the expense ledger.',default=>'Required fields are marked with an asterisk. You can return to edit these details later.' };
@endphp
<div class="page-heading"><div><span class="eyebrow">{{ $def['title'] }}</span><h1>{{ $record->exists?'Edit':'Add' }} {{ $singular }}</h1><p>{{ $intro }}</p></div><a class="btn secondary" href="{{ route('manage.index',$resource) }}"><x-icon name="arrow-left"/>{{ $def['title'] }}</a></div>
<form method="POST" enctype="multipart/form-data" action="{{ $record->exists?route('manage.update',[$resource,$record->id]):route('manage.store',$resource) }}" class="module-editor" data-module-editor>@csrf @if($record->exists)@method('PUT')@endif
<section class="card module-editor-main">
<div class="customer-section-heading"><span class="customer-section-icon"><x-icon :name="$icon"/></span><div><h2>{{ ucfirst($singular) }} details</h2><p>{{ $record->exists?'Update the details and save your changes.':'Start with the essential details below.' }}</p></div>@if($record->exists)<span class="badge slate">#{{ $record->id }}</span>@endif
</div>
<div class="module-fields">
@foreach($def['fields'] as $key=>$field)
@php([$label,$type]=$field) @php($value=old($key,$record->$key))
@if($resource==='users' && $key==='password')@php($label=$record->exists?'New password':'Password')@endif
@if($resource==='payment-rules' && $key==='charge_bearer' && $value===null)@php($value='DEFAULT')@endif
@if($type==='checkbox')
<label class="setting-tile"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$record->exists?$value:($key==='active')))><span><strong>{{ $label }}</strong><small>{{ $key==='active'?'Available for use in your store.':($key==='allow_decimal'?'Allow quantities such as 1.5 or 0.250.':'Enable this option.') }}</small></span></label>
@elseif($type==='permissions')
<section class="permissions-editor full"><div class="permissions-toolbar"><div><h2>Permissions</h2><p><span data-permission-count>0</span> selected · choose access by module</p></div><div class="heading-actions"><button type="button" class="btn secondary small" data-permissions-all="1">Select all</button><button type="button" class="btn secondary small" data-permissions-all="0">Clear</button></div></div><label class="customer-search permission-search"><x-icon name="search"/><input type="search" data-permission-search placeholder="Find a module or permission…" aria-label="Search permissions"></label><div class="permission-cards">
@foreach($permissions->groupBy(fn($p)=>explode('.',$p->name)[0]) as $group=>$items)
<fieldset class="permission-card" data-permission-group><legend>{{ \Illuminate\Support\Str::headline($group) }}</legend><label class="inline-check permission-group-toggle"><input type="checkbox" data-group-toggle>Select module</label>
@foreach($items as $permission)@php($action=\Illuminate\Support\Str::headline(explode('.',$permission->name,2)[1]??$permission->name))
<label class="permission-option" data-permission-label><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id,old('permissions',$record->exists?$record->permissions->pluck('id')->all():[])))><span>{{ $action }}</span></label>
@endforeach
</fieldset>
@endforeach
</div></section>
@else
<label class="field {{ $type==='textarea'?'full':'' }}"><span>{{ $label }} @if(($field[2]??true) || ($resource==='users' && $key==='password' && !$record->exists))<b class="required">*</b>@else
<small>optional</small>@endif
</span>
@if($type==='select' || $type==='options')@if($resource==='expenses' && $key==='expense_category_id')<div class="input-with-action">@endif
<select name="{{ $key }}" @required($field[2]??true)><option value="">Select {{ strtolower($label) }}</option>@foreach($type==='select'?$options[$key]:$field[3] as $id=>$name)<option value="{{ $id }}" @selected((string)$value===(string)$id)>{{ $name }}</option>@endforeach
</select>@if($resource==='expenses' && $key==='expense_category_id')@can('expenses.create')<button type="button" class="btn secondary" data-open-expense-category aria-label="Add expense category" title="Add expense category"><x-icon name="plus"/></button>@endcan
</div>@endif
@elseif($type==='textarea')<textarea name="{{ $key }}" rows="3" placeholder="Add {{ strtolower($label) }}…" @required($field[2]??true)>{{ $value }}</textarea>
@else
<input name="{{ $key }}" type="{{ in_array($type,['money','quantity','decimal','number'])?'number':$type }}" @if(!in_array($type,['file','password']))value="{{ $value??(in_array($type,['money','quantity','decimal','number']) && ($field[2]??true)?0:($type==='date'?today()->toDateString():'')) }}"@endif @if(in_array($type,['money','quantity','decimal','number']))step="{{ match($type){'quantity'=>'0.001','decimal'=>'0.0001','number'=>'1',default=>'0.01'} }}" inputmode="decimal" @if($key!=='priority')min="0"@endif @else placeholder="{{ $type==='password'?'Enter a secure password':'Enter '.strtolower($label) }}"@endif @if($type==='file')accept="image/png,image/jpeg,image/webp"@endif @if($type==='password')autocomplete="new-password" minlength="10"@endif @if($resource==='users' && $key==='username')autocapitalize="none" spellcheck="false"@endif @required(($field[2]??true) || ($resource==='users' && $key==='password' && !$record->exists))>
@endif
@if($key==='password')<small>{{ $record->exists?'Leave blank to keep the current password.':'Use at least 10 characters.' }}</small>@endif
@if($key==='username')<small>Letters, numbers, dots, underscores or hyphens.</small>@endif
@if($key==='charge_bearer')<small>Inherit the payment method default or choose who pays the fee.</small>@endif
@error($key)<small class="text-danger">{{ $message }}</small>@enderror
</label>
@endif
@endforeach
</div></section>
<aside class="card module-editor-help"><span class="customer-section-icon"><x-icon name="info"/></span><h2>{{ match($resource){'roles'=>'Access that fits','users'=>'Ready for your team','units'=>'Count with confidence','expenses'=>'Keep spending clear',default=>'A little detail helps'} }}</h2><p>{{ $help }}</p><div class="module-help-note"><x-icon name="check" :size="18"/><span>Your changes take effect when you save.</span></div>@if($resource==='units' && $record->default_slot===1)<span class="badge green">Default for new products</span>@endif
</aside>
<div class="module-editor-footer"><a class="btn secondary" href="{{ route('manage.index',$resource) }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>{{ $record->exists?'Save changes':'Create '.$singular }}</button></div>
</form>
@if($resource==='expenses')@can('expenses.create')
<dialog id="expense-category-dialog" class="purchase-payment-dialog" aria-labelledby="expense-category-title"><div class="modal-heading"><div><span class="eyebrow">EXPENSE CATEGORIES</span><h2 id="expense-category-title">Add expense category</h2></div><button type="button" class="icon-button" data-close-expense-category aria-label="Close category dialog"><x-icon name="x"/></button></div><form class="modal-body" id="expense-category-quick-form" action="{{ route('manage.store','expense-categories') }}" method="POST">@csrf<p class="muted">Create a category and select it for this expense.</p><div class="notice error" role="alert" data-category-error hidden></div><label class="field">Category name<input name="name" required maxlength="255" autocomplete="off"></label><div class="form-footer"><button type="button" class="btn secondary" data-close-expense-category>Cancel</button><button type="submit" class="btn primary"><x-icon name="check"/>Add category</button></div></form></dialog>
@endcan @endif
@endsection
