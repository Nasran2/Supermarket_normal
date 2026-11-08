<details class="repair-fold repair-unlock" @if($errors->hasAny(['device_unlock','unlock_type'])) open @endif><summary>Optional unlock credential <small>PIN, password or pattern</small></summary>
                <div class="repair-fields repair-credential">
                    <label class="field">Credential type<select name="unlock_type" data-unlock-type><option value="">None</option><option value="PIN">PIN</option><option value="PASSWORD">Password</option><option value="PATTERN">Pattern</option></select></label>
                    <label class="field" data-unlock-text hidden>PIN or password<input type="password" maxlength="150" autocomplete="new-password" data-unlock-secret disabled></label>
                    <div data-unlock-pattern hidden>
                        <p class="muted">Draw or tap at least four dots in order.</p>
                        <div class="repair-pattern" aria-label="Unlock pattern">
                            <svg viewBox="0 0 100 100" aria-hidden="true"><polyline data-pattern-line fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @for($dot=1;$dot<=9;$dot++)<button class="repair-dot" type="button" data-dot="{{ $dot }}" aria-label="Dot {{ $dot }}" aria-pressed="false">{{ $dot }}</button>@endfor
                        </div>
                        <button type="button" class="text-link" data-pattern-clear>Clear pattern</button>
                    </div>
                    <input type="hidden" name="device_unlock" data-unlock-value>
                </div>
            </details>
