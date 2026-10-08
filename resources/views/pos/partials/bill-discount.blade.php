@if($settings['allow_discount']??true)
<dialog id="bill-discount-dialog" class="line-editor-dialog bill-discount-dialog" aria-labelledby="bill-discount-title">
    <div class="modal-heading"><div><span class="eyebrow">FULL BILL</span><h2 id="bill-discount-title">Bill discount</h2></div><button class="icon-button" type="button" id="close-bill-discount" aria-label="Close bill discount"><x-icon name="x"/></button></div>
    <form id="bill-discount-form" class="line-editor-body">
        <p class="muted">Apply a discount to the whole bill, after any item discounts.</p>
        <div class="line-editor-fields">
            <label class="field"><span>Discount type</span><select id="bill-discount-type"><option value="AMOUNT">Amount ({{ $settings['currency_symbol']??'Rs.' }})</option><option value="PERCENT">Percentage (%)</option></select></label>
            <label class="field"><span>Discount value</span><input id="bill-discount-value" type="number" required min="0" max="999999999" step="0.01" inputmode="decimal" value="0"></label>
        </div>
        <div class="bill-preview-breakdown"><div><span>Subtotal after item discounts</span><strong id="bill-preview-subtotal"></strong></div><div><span>Bill discount</span><strong id="bill-preview-discount"></strong></div></div>
        <div class="line-editor-total"><span>Order total</span><strong id="bill-preview-total"></strong></div>
        <div class="line-editor-footer"><button type="button" class="btn secondary" id="remove-bill-discount">Remove discount</button><div><button type="button" class="btn secondary" id="cancel-bill-discount">Cancel</button><button type="submit" class="btn primary"><x-icon name="check"/>Apply discount</button></div></div>
    </form>
</dialog>
@endif
