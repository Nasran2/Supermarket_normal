@foreach([['dashboard.view','dashboard','layout-dashboard','Dashboard'],['pos.access','pos.index','scan-line','Point of sale']] as [$permission,$route,$icon,$label])
@can($permission)<a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($route)?'active':'' }}" title="{{ $label }}"><x-icon :name="$icon"/><span>{{ $label }}</span></a>@endcan
@endforeach
@foreach(\App\Support\Sidebar::sections(auth()->user()) as $section=>$groups)
@if(collect($groups)->contains(fn($group)=>count($group['children'])>0))
@if($section!=='WORKSPACE')<div class="nav-section">{{ $section }}</div>@endif
@foreach($groups as $group)
@if(count($group['children']))
<details class="nav-group" @if($group['active']) open @endif>
    <summary class="nav-link {{ $group['active']?'active':'' }}" title="{{ $group['label'] }}"><x-icon :name="$group['icon']"/><span>{{ $group['label'] }}</span><x-icon name="chevron-down" :size="14" class="nav-chevron"/></summary>
    <div class="nav-children">@foreach($group['children'] as $child)<a class="nav-child {{ $child['active']?'active':'' }}" href="{{ $child['url'] }}" @if($child['active'])aria-current="page"@endif>{{ $child['label'] }}</a>@endforeach</div>
</details>
@endif
@endforeach
@endif
@endforeach
<a href="{{ route('documentation.index') }}" class="nav-link {{ request()->routeIs('documentation.*')?'active':'' }}" title="Documentation" @if(request()->routeIs('documentation.*'))aria-current="page"@endif><x-icon name="book-open"/><span>Documentation</span></a>
