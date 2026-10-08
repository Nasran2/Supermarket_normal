<dialog id="line-dialog" class="line-editor-dialog" aria-labelledby="line-editor-title">
    <div class="modal-heading"><div><span class="eyebrow">ORDER ITEM</span><h2 id="line-editor-title">Edit item</h2></div><button type="button" class="icon-button" id="close-line" aria-label="Close item editor"><x-icon name="x"/></button></div>
    <form id="line-form" class="line-editor-body">
        <p class="muted" id="line-editor-product"></p>
        <div class="line-editor-fields"><label class="field"><span>Unit</span><select id="line-unit" required></select></label><label class="field"><span>Quantity</span><input id="line-quantity" type="number" min="1" max="999999" step="1" inputmode="decimal" required></label></div>
        <label class="field"><span>Unit price ({{ $settings['currency_symbol']??'Rs.' }})</span><input id="line-price" type="number" min="0" max="999999999" step="0.01" inputmode="decimal" required><small id="line-catalog-price"></small></label>
        @if($settings['allow_discount']??true)<div class="line-editor-fields"><label class="field"><span>Line discount</span><select id="line-discount-type"><option value="AMOUNT">Amount ({{ $settings['currency_symbol']??'Rs.' }})</option><option value="PERCENT">Percentage (%)</option></select></label><label class="field"><span>Discount value</span><input id="line-discount-value" type="number" min="0" max="999999999" step="0.01" value="0" inputmode="decimal"><small>Applies to this entire line.</small></label></div>@else<input id="line-discount-type" type="hidden" value="AMOUNT"><input id="line-discount-value" type="hidden" value="0">@endif
        <div class="line-editor-total"><span>Final line amount</span><strong id="line-preview-total"></strong></div>
        <div id="line-editor-error" class="notice danger" role="alert" hidden></div>
        <div class="line-editor-footer"><button type="button" class="btn secondary" id="reset-line-price">Reset pricing</button><div><button type="button" class="btn secondary" id="cancel-line">Cancel</button><button type="submit" class="btn primary"><x-icon name="check"/>Apply changes</button></div></div>
    </form>
</dialog>
<dialog id="cancel-order-dialog" class="cancel-order-dialog" aria-labelledby="cancel-order-title">
    <span class="customer-due-icon"><x-icon name="shopping-basket" :size="26"/></span>
    <h2 id="cancel-order-title">{{ isset($editingSale)?'Reset invoice changes?':'Clear this order?' }}</h2>
    <p>{{ isset($editingSale)?'The saved invoice will be reloaded and unsaved changes discarded.':'The items and saved draft will be cleared. Choose Keep order to continue selling.' }}</p>
    <div><button type="button" class="btn secondary" id="keep-order">{{ isset($editingSale)?'Keep editing':'Keep order' }}</button><button type="button" class="btn danger" id="cancel-order-confirm">{{ isset($editingSale)?'Reset changes':'Clear order' }}</button></div>
</dialog>
