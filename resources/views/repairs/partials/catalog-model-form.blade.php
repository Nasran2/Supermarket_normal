<form class="repair-stack" method="post" action="{{ $model ? route('repairs.catalog.models.update',[$brand,$model]) : route('repairs.catalog.models.store',$brand) }}">
    @csrf @if($model) @method('PUT') @endif
    <div class="repair-fields">
        <label class="field">Model name<input name="name" value="{{ $model?->name }}" required maxlength="120"></label>
        <label class="field">Extra inspection checks<select name="inspection">@foreach(\App\Models\RepairModel::INSPECTIONS as $profile)
            <option @selected($model?->inspection===$profile)>{{ $profile }}</option>
        @endforeach</select></label>
    </div>
    <label class="repair-setting-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($model?->active ?? true)> Show this model at intake</label>
    <div><button class="btn primary">{{ $model ? 'Save model' : 'Add model' }}</button></div>
</form>
