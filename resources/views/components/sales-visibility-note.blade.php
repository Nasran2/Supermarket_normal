@if(\App\Support\SalesVisibility::mode() !== 'ALL')
<p class="sales-access-badge"><x-icon name="shield-check" :size="16"/>Sales access: {{ \App\Support\SalesVisibility::OPTIONS[\App\Support\SalesVisibility::mode()] }} · Totals follow this access.</p>
@endif
