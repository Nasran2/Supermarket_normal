@can('sms.receipt')
<dialog id="sms-receipt-dialog" class="payment-dialog sms-receipt-dialog" data-customers-url="{{ route('sms.customers') }}" aria-labelledby="sms-receipt-title" aria-describedby="sms-receipt-description">
    <form id="sms-receipt-form">
        <div class="modal-heading">
            <div class="sms-receipt-heading"><span class="sms-icon-tile"><x-icon name="send" :size="20"/></span><div><h2 id="sms-receipt-title">Send receipt SMS</h2><p id="sms-receipt-description">A receipt link, straight to your customer's phone.</p></div></div>
            <button type="button" class="icon-button" data-sms-close aria-label="Close SMS dialog"><x-icon name="x" :size="18"/></button>
        </div>
        <div class="modal-body">
            <p id="sms-receipt-status" role="status" aria-live="polite" class="sms-receipt-status" hidden></p>
            <div id="sms-customer-fields" hidden>
                <div id="sms-recipient-modes" class="sms-recipient-modes" role="group" aria-label="Receipt recipient">
                    <button type="button" data-sms-mode="existing" aria-pressed="true"><x-icon name="users" :size="16"/>Existing customer</button>
                    <button type="button" data-sms-mode="new" aria-pressed="false"><x-icon name="plus" :size="16"/>New customer</button>
                </div>
                <div id="sms-existing-fields" class="sms-recipient-section">
                    <label class="field" for="sms-customer-search"><span>Find a customer</span><input id="sms-customer-search" type="search" maxlength="100" placeholder="Search by name or phone" autocomplete="off"></label>
                    <label class="field" for="sms-customer-select"><span>Choose a customer</span><select name="customer_id" id="sms-customer-select"><option value="">Select a customer…</option></select></label>
                    <p id="sms-customer-search-status" class="sms-receipt-help" role="status" hidden></p>
                </div>
                <div id="sms-new-fields" class="sms-recipient-section" hidden>
                    <label class="field" for="sms-customer-name"><span>Customer name</span><input name="name" id="sms-customer-name" maxlength="255" placeholder="Enter customer name" autocomplete="name" disabled></label>
                </div>
            </div>
            <div id="sms-receipt-recipient" class="sms-receipt-recipient" hidden>
                <span class="sms-icon-tile"><x-icon name="user" :size="18"/></span><div><small>Sending to</small><strong id="sms-recipient-name"></strong><span id="sms-recipient-phone"></span></div>
            </div>
            <div id="sms-phone-fields" class="sms-recipient-section" hidden>
                <label class="field" for="sms-customer-phone"><span id="sms-phone-label">Mobile number</span><input name="phone" id="sms-customer-phone" type="tel" maxlength="40" placeholder="077 123 4567" autocomplete="tel" aria-describedby="sms-phone-help" disabled></label>
                <p id="sms-phone-help" class="sms-receipt-help"></p>
            </div>
            <p class="sms-receipt-link-note"><x-icon name="link" :size="15"/>The customer can open the receipt without logging in.</p>
        </div>
        <div class="receipt-actions"><button type="button" class="btn secondary" id="sms-receipt-cancel" data-sms-close>Cancel</button><button type="submit" class="btn primary" id="sms-receipt-submit" disabled><x-icon name="send" :size="16"/><span id="sms-receipt-submit-label">Send receipt</span></button></div>
    </form>
</dialog>
@endcan
