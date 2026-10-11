<div class="field">
    <label for="{{ $key }}-upload">{{ $label }}</label>
    <input type="file" id="{{ $key }}-upload" name="{{ $key }}" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="{{ $label }}" onchange="document.getElementById('remove-{{ $key }}-flag').value='0';document.getElementById('{{ $key }}-filename').textContent=this.files[0]?this.files[0].name:'No file selected'">
    <input type="hidden" name="remove_{{ $key }}" id="remove-{{ $key }}-flag" value="0">
    <button type="button" class="btn secondary w-fit" onclick="document.getElementById('{{ $key }}-upload').click()">{{ $key==='logo_secondary'?'Choose second logo':'Change logo' }}</button>
    <small id="{{ $key }}-filename">Select a file, then save your changes.</small>
    @if($key==='logo_secondary')<small>Upload a separate picture. It appears beside the business logo at the top of sales bills and return slips.</small>@endif
    @if(!empty($values[$key]))
        <img id="current-{{ $key }}" class="image-preview" src="{{ asset('storage/'.$values[$key]) }}" alt="{{ $key==='logo_secondary'?'Current second bill logo':'Current business logo' }}">
        <button type="button" class="btn danger w-fit" onclick="document.getElementById('remove-{{ $key }}-flag').value='1';document.getElementById('{{ $key }}-upload').value='';document.getElementById('current-{{ $key }}').style.display='none';this.textContent='Logo will be removed on save'">{{ $key==='logo_secondary'?'Remove second logo':'Remove logo' }}</button>
    @endif
    @error($key)<small class="text-danger">{{ $message }}</small>@enderror
    @include('partials.bill-visibility')
</div>
