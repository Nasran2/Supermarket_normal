<dialog id="checkout-customer-dialog" class="customer-dialog" aria-labelledby="checkout-customer-title">
    <div class="modal-heading"><div><span class="eyebrow">CUSTOMER ACCOUNT</span><h2 id="checkout-customer-title">Select a customer</h2></div><button class="icon-button" type="button" id="close-checkout-customer" aria-label="Close customer selection"><x-icon name="x"/></button></div>
    <form id="checkout-customer-form" class="customer-dialog-body">
        <div class="checkout-customer-balance"><span>Balance to leave due</span><strong id="checkout-customer-due"></strong></div>
        <p class="muted" id="checkout-customer-help">Select an existing customer or add a new customer for this sale.</p>
        <label class="field">Customer<select id="checkout-customer-select" required><option value="">Select a customer</option></select></label>
        <button type="button" class="btn secondary w-full" id="checkout-add-customer"><x-icon name="plus"/>Add new customer</button>
        <div class="customer-dialog-actions"><button class="btn secondary" type="button" id="cancel-checkout-customer">Back to payment</button><button class="btn primary" type="submit" id="use-checkout-customer"><x-icon name="check"/>Use customer &amp; complete</button></div>
        <button class="text-link checkout-walk-in" id="checkout-walk-in" type="button">Continue as walk-in customer</button>
    </form>
</dialog>
