<div class="repair-chips">
    @foreach($choices as $choice)
    <label class="repair-chip"><input type="checkbox" name="{{ $name }}[]" value="{{ $choice }}" @checked(in_array($choice,(array)old($name,[]),true))><span>{{ $choice }}</span></label>
    @endforeach
</div>
