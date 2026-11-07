<form method="POST" action="{{ route('sales.privacy-view') }}" class="sales-privacy-toolbar" data-privacy-toolbar
    @if($surface === 'pos' && in_array('sales', $privacy['applies_to'], true)) data-privacy-preview="{{ route('sales.index') }}" @endif>
    @csrf
    <input type="hidden" name="surface" value="{{ $surface }}">
    <div class="sales-privacy-buttons" role="group" aria-label="Sales privacy view">
        @foreach(\App\Support\SalesPrivacy::MODES as $mode => $label)
            @php($key = \App\Support\SalesPrivacy::SHORTCUTS[$mode] ?? null)
            <button type="submit" class="btn {{ $privacy['mode'] === $mode ? 'primary' : 'secondary' }}"
                name="mode" value="{{ $mode }}" aria-pressed="{{ $privacy['mode'] === $mode ? 'true' : 'false' }}"
                @if($key) data-privacy-key="{{ $key }}" aria-keyshortcuts="Control+{{ $key }} Meta+{{ $key }}" @endif>
                {{ $label }} @if($key)<kbd>Ctrl / ⌘ + {{ $key }}</kbd>@endif
            </button>
        @endforeach
    </div>
    <small class="muted" role="status" data-privacy-status>
        @if($privacy['mode'] === 'all')
            Choose a view to show up to {{ $privacy['bill_count'] }} matching bills.
        @else
            {{ \App\Support\SalesPrivacy::MODES[$privacy['mode']] }} · Up to {{ $privacy['bill_count'] }} matching bills.
        @endif
    </small>
</form>

@once
    @push('head')
        <style>
            .sales-privacy-toolbar { display: grid; justify-items: start; gap: 8px; margin-bottom: 16px; }
            .sales-privacy-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
            .sales-privacy-buttons kbd { padding: 2px 5px; border: 1px solid currentColor; border-radius: 4px; font-size: 0.72rem; }
            .pos-heading-actions:has(.sales-privacy-toolbar) { flex-wrap: wrap; }
            .pos-heading-actions .sales-privacy-toolbar { margin-bottom: 0; }
            .sales-directory .sales-privacy-toolbar, .customer-purchase-history .sales-privacy-toolbar { margin-inline: 24px; }
            @media print { .sales-privacy-buttons { display: none; } }
        </style>
    @endpush
    @push('scripts')
        <script>
            (() => {
                const form = document.querySelector('[data-privacy-toolbar]');
                const status = form.querySelector('[data-privacy-status]');
                const isMac = /Mac|iPhone|iPad/i.test(navigator.userAgentData?.platform || navigator.platform);
                let pending = false;

                for (const button of form.querySelectorAll('[data-privacy-key]')) {
                    const key = button.dataset.privacyKey;
                    button.querySelector('kbd').textContent = isMac ? `⌘ + ${key}` : `Ctrl + ${key}`;
                }

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const button = event.submitter;
                    if (!button || pending) return;
                    pending = true;
                    const previewUrl = button.value === 'all' ? null : form.dataset.privacyPreview;
                    const preview = previewUrl ? window.open('about:blank', '_blank') : null;
                    if (preview) preview.opener = null;
                    form.setAttribute('aria-busy', 'true');
                    try {
                        const data = new FormData(form);
                        data.set('mode', button.value);
                        const response = await fetch(form.action, {
                            method: 'POST', body: data, headers: { Accept: 'application/json' },
                        });
                        if (!response.ok || response.redirected) throw new Error('Privacy view unavailable');
                        if (data.get('surface') === 'pos') {
                            for (const option of form.querySelectorAll('button')) {
                                option.setAttribute('aria-pressed', String(option === button));
                                option.classList.toggle('primary', option === button);
                                option.classList.toggle('secondary', option !== button);
                            }
                            status.textContent = `${button.firstChild.textContent.trim()} view active.`;
                            if (preview) preview.location.replace(previewUrl);
                        } else {
                            const url = new URL(window.location.href);
                            url.searchParams.delete('page');
                            window.location.assign(url);
                        }
                    } catch {
                        preview?.close();
                        status.textContent = 'Could not switch sales views. Refresh and try again.';
                    } finally {
                        pending = false;
                        form.removeAttribute('aria-busy');
                    }
                });

                document.addEventListener('keydown', (event) => {
                    const modifier = isMac ? event.metaKey && !event.ctrlKey : event.ctrlKey && !event.metaKey;
                    if (!modifier || event.defaultPrevented || event.repeat || event.isComposing ||
                        event.altKey || event.shiftKey || document.querySelector('dialog[open]')) return;

                    const key = event.key.toUpperCase();
                    if (key !== 'D' && key !== 'X') return;
                    const target = event.target;
                    if (key === 'X' && (target.isContentEditable || target.selectionStart < target.selectionEnd)) return;

                    const button = form.querySelector(`[data-privacy-key="${key}"]`);
                    if (!button) return;
                    event.preventDefault();
                    form.requestSubmit(button);
                });
            })();
        </script>
    @endpush
@endonce
