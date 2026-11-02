@if(config('features.customer_tax'))
<dialog id="bill-type-dialog" class="customer-dialog bill-type-dialog" aria-labelledby="bill-type-title">
    <div class="modal-heading"><div><span class="eyebrow">PAYMENT SAVED</span><h2 id="bill-type-title">Choose a bill</h2></div><button type="button" class="icon-button" id="close-bill-type" aria-label="Cancel bill selection"><x-icon name="x"/></button></div>
    <div class="customer-dialog-body"><p class="muted">Sale <strong id="bill-type-invoice"></strong> is complete. Choose what to give your customer.</p>
        <div class="bill-type-grid">
            <button type="button" id="bill-normal" class="bill-type-option">
                <span class="bill-type-option-heading"><span class="bill-type-key">1</span><span class="bill-type-copy"><strong>Normal bill</strong><small>Your usual printed receipt</small></span><x-icon name="printer"/></span>
                <span class="bill-preview-stage">@include('pos.partials.bill-preview', ['previewType' => 'receipt'])</span>
                <span class="bill-type-option-action">Print receipt <x-icon name="arrow-right" :size="17"/></span>
            </button>
            <button type="button" id="bill-tax" class="bill-type-option">
                <span class="bill-type-option-heading"><span class="bill-type-key">2</span><span class="bill-type-copy"><strong>Tax invoice</strong><small>A4 invoice with customer TIN</small></span><x-icon name="file-text"/></span>
                <span class="bill-preview-stage">@include('pos.partials.bill-preview', ['previewType' => 'tax'])</span>
                <span class="bill-type-option-action">Preview &amp; print <x-icon name="arrow-right" :size="17"/></span>
            </button>
        </div>
        <p class="bill-preview-caption">Sample layouts for illustration only.</p>
        <p id="bill-type-error" class="notice error" role="alert" hidden></p>
        <div class="customer-dialog-actions"><button type="button" class="btn secondary" id="cancel-bill-type">Cancel <kbd>Esc</kbd></button></div>
    </div>
</dialog>
<dialog id="tax-customer-dialog" class="customer-dialog tax-customer-dialog" aria-labelledby="tax-customer-title">
    <div class="modal-heading"><div><span class="eyebrow">TAX INVOICE</span><h2 id="tax-customer-title">Complete customer details</h2></div><button type="button" class="icon-button" id="close-tax-customer" aria-label="Back to bill selection"><x-icon name="x"/></button></div>
    <form novalidate id="tax-customer-form" class="customer-dialog-body">
        <div class="tax-customer-fields">
        <p id="tax-customer-help" class="muted">These details are required on the tax invoice. Missing information will also be saved to the customer.</p>
        <div id="tax-customer-error" class="notice error" role="alert" hidden></div>
        <label class="field" id="tax-customer-picker">Select a customer or add new<select id="tax-customer-select" name="customer_id"><option value="">Add a new customer</option></select></label>
        <label class="field"><span>Customer / business name <span class="required-mark">*</span></span><input name="name" required maxlength="255" autocomplete="name"></label>
        <div class="customer-contact-fields"><label class="field"><span>TIN / VAT number <span class="required-mark">*</span></span><input name="tax_number" required maxlength="100" placeholder="Purchaser's TIN"></label><label class="field"><span>Telephone <span class="required-mark">*</span></span><input name="phone" type="tel" required maxlength="255" autocomplete="tel"></label></div>
        <label class="field"><span>Address <span class="required-mark">*</span></span><textarea name="address" required maxlength="2000" rows="2" autocomplete="street-address"></textarea></label>
        <fieldset id="tax-supplier-section" class="tax-supplier-section" hidden>
            <legend>Your shop details</legend>
            <p class="muted text-sm">The supplier is your shop. These details are separate from the customer's and will be saved for future tax invoices.</p>
            <p id="tax-supplier-permission" class="notice info" hidden>A manager needs to complete your shop's details in Business information before a tax PDF can be issued. Your entered customer details will stay here.</p>
            <div id="tax-supplier-fields">
                <label class="field"><span>Shop / business name <span class="required-mark">*</span></span><input name="supplier_business_name" maxlength="150"></label>
                <div class="customer-contact-fields"><label class="field"><span>Shop TIN / VAT number <span class="required-mark">*</span></span><input name="supplier_tax_number" maxlength="100" placeholder="Your shop's TIN"></label><label class="field"><span>Shop telephone <span class="required-mark">*</span></span><input name="supplier_phone" type="tel" maxlength="40"></label></div>
                <label class="field"><span>Shop address <span class="required-mark">*</span></span><textarea name="supplier_address" rows="2" maxlength="500"></textarea></label>
            </div>
        </fieldset>
        <details class="tax-delivery-details"><summary>Delivery &amp; additional information</summary><div class="customer-contact-fields"><label class="field">Date of delivery<input type="date" name="delivery_date" required></label><label class="field">Place of supply<input name="place_of_supply" required maxlength="2000" placeholder="Filled from customer address"></label></div><label class="field">Additional information <span class="optional-label">optional</span><textarea name="additional_information" maxlength="1000" rows="2"></textarea></label></details>
        </div>
        <div class="customer-dialog-actions"><button class="btn secondary" type="button" id="cancel-tax-customer">Back</button><button class="btn primary" type="submit" id="save-tax-customer"><x-icon name="printer"/>Save &amp; print</button></div>
    </form>
</dialog>
<dialog id="tax-receipt-dialog" class="receipt-dialog tax-receipt-dialog" aria-labelledby="tax-receipt-title">
    <div class="modal-heading"><div><span class="eyebrow">TAX INVOICE · PRINT VIEW</span><h2 id="tax-receipt-title">Tax invoice</h2></div><button class="icon-button" type="button" id="close-tax-receipt" aria-label="Close tax invoice"><x-icon name="x"/></button></div>
    <iframe id="tax-receipt-frame" title="Tax invoice print preview"></iframe>
    <p id="tax-receipt-error" class="notice error" role="alert" hidden></p>
    <div class="receipt-actions">
        <a class="btn secondary" id="tax-receipt-external" target="_blank" rel="noopener" aria-keyshortcuts="1"><span class="tax-action-label">Open invoice</span><kbd>1</kbd></a>
        <button class="btn secondary" id="download-tax-receipt" type="button" aria-keyshortcuts="2"><span class="tax-action-label"><x-icon name="download"/>Download PDF</span><kbd>2</kbd></button>
        <button class="btn secondary" id="print-tax-receipt" type="button" aria-keyshortcuts="3" disabled><span class="tax-action-label"><x-icon name="printer"/>Print</span><kbd>3</kbd></button>
        <button class="btn primary" id="next-sale-tax" type="button" aria-keyshortcuts="4 Escape"><span class="tax-action-label" id="next-sale-tax-label">Done</span><span class="tax-shortcut-hint"><kbd>4</kbd><span> / </span><kbd>Esc</kbd></span></button>
    </div>
</dialog>
@endif
