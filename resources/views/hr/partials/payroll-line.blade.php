<div class="hr-payroll-line" data-hr-payroll-line>
    <label class="field">Type
        <select name="lines[{{ $index }}][kind]">
            <option value="EARNING" @selected(($line['kind'] ?? 'EARNING') === 'EARNING')>Allowance / earning</option>
            <option value="DEDUCTION" @selected(($line['kind'] ?? '') === 'DEDUCTION')>Deduction</option>
        </select>
    </label>
    <label class="field">Description
        <input name="lines[{{ $index }}][name]" value="{{ $line['name'] ?? '' }}" placeholder="e.g. Travel allowance" maxlength="100">
        @error('lines.'.$index.'.name')<small class="text-danger">{{ $message }}</small>@enderror
    </label>
    <label class="field">Amount
        <input name="lines[{{ $index }}][amount]" type="number" min="0" step="0.01" value="{{ $line['amount'] ?? 0 }}" required>
    </label>
    <button type="button" class="btn secondary" data-hr-remove-line aria-label="Remove salary line"><x-icon name="x" :size="17"/></button>
</div>
