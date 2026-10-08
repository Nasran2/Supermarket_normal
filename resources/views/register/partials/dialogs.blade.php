<div id="register-workflow" data-open-url="{{ route('register.open') }}" data-close-url="{{ route('register.close') }}" data-summary-url="{{ route('register.current-summary') }}" data-current="{{ $currentRegister?1:0 }}" data-required="{{ request()->routeIs('pos.index') && !$currentRegister?1:0 }}" data-currency="{{ $settings['currency_symbol']??'Rs.' }}">
    <dialog id="open-register-dialog" class="register-dialog register-opening-dialog" aria-labelledby="open-register-title">
        <div class="modal-heading"><div><span class="eyebrow">START YOUR SHIFT</span><h2 id="open-register-title">Open register</h2></div><button class="icon-button" type="button" data-dismiss-register aria-label="Close register opening"><x-icon name="x"/></button></div>
        <div class="register-dialog-body"><div class="register-welcome"><span class="register-welcome-icon"><x-icon name="wallet" :size="26"/></span><p>Count your opening cash to unlock the POS and start selling.</p></div>
            <div id="open-register-error" class="notice error" role="alert" hidden></div>
            @can('register.open')<form id="open-register-form" method="POST" action="{{ route('register.open') }}">@csrf<label class="field">Opening cash ({{ $settings['currency_symbol']??'Rs.' }})<input name="opening_cash" type="text" inputmode="decimal" value="{{ old('opening_cash','0') }}" data-register-amount aria-label="Opening cash" autocomplete="off" required maxlength="15"></label>
                @include('register.partials.keypad')
                <button class="btn primary w-full" type="submit"><x-icon name="wallet"/>Open register & start selling</button>
            </form>@else<div class="notice info">Ask a manager to enable register opening for your account.</div>@endcan
            <a class="text-link register-back" href="{{ \App\Support\Navigation::home(auth()->user()) === route('pos.index') ? route('profile') : \App\Support\Navigation::home(auth()->user()) }}">Back to workspace</a>
        </div>
    </dialog>
    <dialog id="close-register-dialog" class="register-dialog register-closing-dialog" aria-labelledby="close-register-title">
        <div class="modal-heading"><div><span class="eyebrow">REVIEW YOUR SHIFT</span><h2 id="close-register-title">Close register</h2></div><button class="icon-button" type="button" data-dismiss-register aria-label="Close register summary"><x-icon name="x"/></button></div>
        <div class="register-dialog-body"><div id="close-register-error" class="notice error" role="alert" hidden></div><div id="register-summary-content" aria-live="polite"></div>
            @can('register.close')<form id="close-register-form" method="POST" action="{{ route('register.close') }}">@csrf<input name="register_id" type="hidden"><div class="register-count-panel"><div><h3>Count your cash</h3><p class="muted text-sm">Enter the cash physically in your drawer.</p><label class="field">Actual cash counted ({{ $settings['currency_symbol']??'Rs.' }})<input name="actual_cash" type="text" inputmode="decimal" data-register-amount aria-label="Actual cash counted" autocomplete="off" maxlength="15" required></label><div class="register-difference"><span>Cash difference</span><strong id="register-live-difference">—</strong></div><label class="field">Closing notes<textarea name="notes" rows="2" maxlength="2000" placeholder="Optional notes for this shift"></textarea></label></div>@include('register.partials.keypad')</div><p class="muted text-xs">Closing locks the sales in this shift. A new register must be opened before selling again.</p><button class="btn primary w-full" type="submit" disabled><x-icon name="check"/>Confirm & close register</button></form>@endcan
            <button id="register-summary-done" class="btn primary w-full" type="button" hidden>Done</button>
        </div>
    </dialog>
</div>
