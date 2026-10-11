@if($comparison[$side.'_empty'])
    <p class="audit-empty">{{ $side==='before'?'No previous values recorded':'No values recorded afterwards' }}</p>
@else
    @php($main=collect($comparison['fields'])->where('detail',false))
    @php($details=collect($comparison['fields'])->where('detail',true))
    @if($main->isNotEmpty())
        <p class="audit-change-count">{{ $main->count() }} {{ \Illuminate\Support\Str::plural('changed field',$main->count()) }}</p>
        <dl class="audit-values">
            @foreach($main->take(6) as $field)<div class="audit-value changed {{ $side }}"><dt>{{ $field['label'] }}</dt><dd>{{ $field[$side] }}</dd></div>@endforeach
        </dl>
        @if($main->count()>6)<details class="audit-other"><summary>Show {{ $main->count()-6 }} more changed fields</summary><dl class="audit-values">@foreach($main->slice(6) as $field)<div class="audit-value changed {{ $side }}"><dt>{{ $field['label'] }}</dt><dd>{{ $field[$side] }}</dd></div>@endforeach</dl></details>@endif
    @else
        <p class="audit-empty">No field values changed.</p>
    @endif
    @if($details->isNotEmpty())
        <details class="audit-other"><summary>Other details ({{ $details->count() }})</summary><dl class="audit-values">
            @foreach($details as $field)<div class="audit-value {{ $field['changed']?'changed '.$side:'' }}"><dt>{{ $field['label'] }}</dt><dd>{{ $field[$side] }}</dd></div>@endforeach
        </dl></details>
    @endif
@endif
