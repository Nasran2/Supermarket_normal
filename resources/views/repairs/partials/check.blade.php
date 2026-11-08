<div class="repair-check-card" data-repair-check @if(empty($custom)) data-check-label="{{ $row['label'] ?? '' }}" @endif>
    <div class="repair-check-name">
        @if(!empty($custom))
            <label class="field"><span class="sr-only">Custom check name</span><input name="checklist[{{ $i }}][label]" maxlength="80" value="{{ $row['label'] ?? '' }}" placeholder="e.g. SIM tray"></label>
            <button class="repair-check-remove" type="button" data-remove-check aria-label="Remove custom check">×</button>
        @else
            <strong data-check-title>{{ $row['label'] ?? '' }}</strong>
            <input type="hidden" name="checklist[{{ $i }}][label]" value="{{ $row['label'] ?? '' }}" data-check-title-input>
        @endif
    </div>
    <div class="repair-check-choice" role="group" aria-label="{{ !empty($custom) ? 'Custom check result' : ($row['label'] ?? '').' result' }}">
        @foreach(['Working'=>'Works','Not working'=>'Issue','Not tested'=>'Skip'] as $value=>$label)
            <label><input type="radio" name="checklist[{{ $i }}][result]" value="{{ $value }}" @checked(($row['result']??null)===$value)><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div class="repair-check-note" data-check-note @if(empty($row['fault']) && ($row['result']??null)!=='Not working') hidden @endif>
        <label class="field"><span class="sr-only">Inspection note</span><input name="checklist[{{ $i }}][fault]" value="{{ $row['fault'] ?? '' }}" maxlength="250" placeholder="Add a short note"></label>
    </div>
</div>
