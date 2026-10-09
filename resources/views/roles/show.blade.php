@extends('layouts.app')
@section('title',$record->name.' permissions')
@section('content')
@php
$admin=$record->system && $record->name==='Administrator';
$catalog=\App\Support\Permissions::all();
$canManage=auth()->user()->isAdministrator() || $record->permissions->every(fn($p)=>auth()->user()->hasPermission($p->name));
$enabled=$admin?array_keys($catalog):$record->permissions->pluck('name')->intersect(array_keys($catalog))->all();
$groups=collect($enabled)->groupBy(fn($name)=>$catalog[$name]['group']);
@endphp
<div class="page-heading"><div><span class="eyebrow">TEAM ACCESS</span><h1>{{ $record->name }}</h1><p>{{ $admin?'Every permission is granted automatically.':'Review the access available to members of this role.' }}</p></div><div class="heading-actions"><a class="btn secondary" href="{{ route('manage.index','roles') }}"><x-icon name="arrow-left"/>Roles</a>@if(!$admin && $canManage)@can('roles.edit')<a class="btn primary" href="{{ route('manage.edit',['roles',$record->id]) }}"><x-icon name="pencil"/>Edit permissions</a>@endcan @endif</div></div>
<div class="role-overview"><div class="card role-overview-card"><span class="stat-icon green"><x-icon name="shield-check"/></span><div><strong>{{ $admin?'Full access':count($enabled) }}</strong><span>{{ $admin?'No selection required':'Enabled permissions' }}</span></div></div><div class="card role-overview-card"><span class="stat-icon blue"><x-icon name="users"/></span><div><strong>{{ $record->users()->count() }}</strong><span>Assigned accounts</span></div></div><div class="card role-admin-note"><x-icon name="info"/><div><strong>{{ $admin?'Includes all new features':'Changes apply to all role members' }}</strong><p>{{ $admin?'New permissions are included without editing this role.':'Actions and data are checked on the server as well as in the interface.' }}</p></div></div></div>
<section class="card role-access-card"><div class="card-heading"><div><h2>Enabled access</h2><p>{{ $groups->count() }} modules · {{ count($enabled) }} permissions</p></div><span class="badge {{ $admin?'green':'slate' }}">{{ $admin?'Automatic':'Custom selection' }}</span></div><div class="permission-cards role-readonly-permissions">@forelse($groups as $group=>$names)<fieldset class="permission-card"><legend>{{ $group }}</legend>@foreach($names as $name)<div class="permission-option"><x-icon name="check" :size="17"/><span><strong>{{ $catalog[$name]['label'] }}</strong><small>{{ $catalog[$name]['description'] }}</small></span></div>@endforeach</fieldset>@empty<div class="empty-state"><h2>No access selected</h2><p>Edit this role to choose the pages and actions it needs.</p></div>@endforelse</div></section>
@endsection
