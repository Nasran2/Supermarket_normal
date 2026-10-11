@php($option = config('bill_fields.'.$group.'.'.$key))
@if($option)
    <label class="bill-visibility">
        <input type="hidden" name="{{ $option['setting'] }}" value="0">
        <input type="checkbox" name="{{ $option['setting'] }}" value="1" @checked(old($option['setting'], $values[$option['setting']] ?? $option['default'])) aria-label="Show {{ $label }} on bill">
        <span>Show on bill</span>
    </label>
    @error($option['setting'])<small class="text-danger">{{ $message }}</small>@enderror
@endif
