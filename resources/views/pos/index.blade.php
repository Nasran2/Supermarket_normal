@extends('layouts.app')
@section('title',isset($editingSale)?'Edit sale':'Point of sale')
@section('body-class','pos-workspace')
@section('main-class','pos-main')
@section('content')
<div class="pos-heading"><div><span class="eyebrow">{{ isset($editingSale)?'INVOICE WORKSPACE':'YOUR CHECKOUT WORKSPACE' }}</span><h1>{{ isset($editingSale)?'Edit '.$editingSale->invoice:'Point of sale' }}</h1>@isset($editingSale)<p class="pos-edit-description">Update the saved invoice. Unsaved changes reset when you refresh or return.</p>@endisset</div><div class="pos-heading-actions">@isset($editingSale)<a class="btn secondary" href="{{ route('sales.show',$editingSale) }}"><x-icon name="arrow-left"/>Cancel edit</a>@endisset<span class="badge {{ $register?'green':'amber' }}">{{ $register?'REG-'.$register->id.' · Ready to sell':'Register closed' }}</span>@if($register)@can('register.view')<button class="btn secondary pos-close-register" type="button" data-close-register><x-icon name="wallet"/>Close register</button>@endcan
@endif<span class="pos-shortcut">F1 Search <span>·</span> F4 Payment</span></div></div>
@if(!$register)
<div class="card pos-register-gate"><span class="register-welcome-icon"><x-icon name="wallet" :size="30"/></span><h2>Open a register to start selling</h2><p>Your POS will unlock once you enter the opening cash.</p><button class="btn primary" type="button" data-open-register>Open register</button></div>
@else
<div id="pos" data-user-id="{{ auth()->id() }}" data-allow-discount="{{ ($settings['allow_discount']??true)?1:0 }}" data-status-url="{{ route('pos.checkout-status') }}" data-products-url="{{ isset($editingSale)?route('sales.edit.products',$editingSale):route('pos.products') }}" data-quote-url="{{ isset($editingSale)?route('sales.edit.quote',$editingSale):route('pos.quote') }}" data-complete-url="{{ isset($editingSale)?route('sales.revise',$editingSale):route('pos.complete') }}" data-currency="{{ $settings['currency_symbol']??'Rs.' }}" data-quantity-precision="{{ $settings['quantity_decimals']??3 }}" data-decimals="{{ $settings['number_decimals']??2 }}" data-barcode="{{ ($settings['barcode_enabled']??true)?1:0 }}" data-default-method="{{ $settings['default_payment_method']??'' }}" data-register="{{ $register?1:0 }}">
@isset($editSeed)<script type="application/json" id="sale-edit-seed">{!! json_encode($editSeed, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>@endisset
<div class="pos-grid">
<section class="pos-catalog">
    <div class="catalog-tools"><label class="search-field"><x-icon name="search"/><input id="product-search" type="search" maxlength="255" placeholder="{{ ($settings['barcode_enabled']??true)?'Scan a barcode or search products…':'Search products…' }}" autocomplete="off" aria-label="Find products" autofocus><kbd>F1</kbd></label></div>
    <div class="category-chips" aria-label="Product categories"><input type="hidden" id="product-category" value=""><button class="category-chip selected" type="button" data-category="" aria-pressed="true">All products</button>@foreach($categories as $c)<button class="category-chip" type="button" data-category="{{ $c->id }}" aria-pressed="false">{{ $c->name }}</button>@endforeach</div>
    <div class="catalog-status"><span id="product-count">Loading products…</span><span>Tap a product to add it</span></div><div id="product-results" class="product-grid" aria-live="polite"></div>
</section>
<aside class="card cart-panel {{ isset($editingSale)?'sale-edit-cart':'' }}">
    <div class="card-heading"><div><span class="eyebrow">{{ isset($editingSale)?$editingSale->invoice:'CURRENT ORDER' }}</span><h2>{{ isset($editingSale)?'Invoice items':'Your cart' }} <span id="cart-count" class="badge slate">0</span></h2></div><button class="text-link text-danger" id="clear-cart" type="button">{{ isset($editingSale)?'Reset changes':'Clear order' }}</button></div>
    <div class="cart-customer"><div class="customer-field-top"><label class="customer-field-label" for="customer">Customer</label><div id="customer-due" class="customer-due-strip" hidden><span>Customer balance</span><strong id="customer-due-amount"></strong></div></div><div class="customer-picker"><select id="customer"><option value="" @selected(isset($editingSale)&&!$editingSale->customer_id)>Walk-in customer</option>@foreach($customers as $c)<option value="{{ $c->id }}" data-due="{{ $c->due_balance }}" @selected((isset($editingSale)?$editingSale->customer_id:($settings['default_customer']??null))==$c->id)>{{ $c->name }}</option>@endforeach</select>@can('sales.create')<button class="btn secondary add-customer-button" id="add-customer" type="button" aria-label="Add customer" title="Add customer"><x-icon name="plus"/></button>@endcan</div><span id="customer-added-status" class="sr-only" role="status"></span></div>
    @isset($editingSale)<details class="pos-edit-notes"><summary>Invoice notes</summary><label class="field"><span class="sr-only">Invoice notes</span><textarea id="edit-notes" maxlength="1000" rows="2">{{ $editingSale->notes }}</textarea></label></details>@endisset
    <div class="cart-column-labels"><span>ITEM & QUANTITY</span><span>AMOUNT</span></div><div id="cart-items" class="cart-items" aria-live="polite"></div>
    <div class="cart-bottom">
        <div class="order-summary"><span>Subtotal</span><strong id="cart-subtotal">0.00</strong></div>
        <input id="discount" type="hidden" value="0">
        @if($settings['allow_discount']??true)<div class="bill-discount-row"><button type="button" class="text-link" id="edit-bill-discount">Bill discount <span id="bill-discount-label">Add</span></button><strong id="bill-discount-amount" hidden></strong></div><p id="bill-discount-warning" class="text-danger text-xs" role="alert" hidden>Bill discount exceeds the subtotal. Edit it before payment.</p>@endif
        <div class="order-summary total"><span>Order total</span><strong id="cart-total">0.00</strong></div>
        <p class="muted text-xs">{{ isset($editingSale)?'Review the revised payments before saving this invoice.':'Pay with one method or split the payment.' }}</p>
        <button id="open-payment" class="btn primary checkout-button" type="button" @disabled(!$register)><x-icon name="credit-card"/>{{ isset($editingSale)?'Review payment':'Take payment' }} <x-icon name="arrow-right"/></button>
        <div id="pos-message" role="status" class="pos-message"></div>
    </div>
</aside>

</div>
@include('pos.partials.price-choice')
@include('pos.partials.line-editor')
@include('pos.partials.bill-discount')
@can('sales.create')
<dialog id="customer-dialog" class="customer-dialog" aria-labelledby="customer-dialog-title" data-store-url="{{ route('pos.customers.store') }}">
    <div class="modal-heading"><div><span class="eyebrow">CUSTOMER DETAILS</span><h2 id="customer-dialog-title">Add customer</h2></div><button class="icon-button" id="close-customer" type="button" aria-label="Close add customer"><x-icon name="x"/></button></div>
    <form id="customer-form" class="customer-dialog-body">
        <p class="muted text-sm">Save a new customer and use them for this order.</p>
        <div id="customer-error" class="notice error" role="alert" hidden></div>
        <label class="field"><span>Customer name <span class="required-mark">*</span></span><input id="customer-name" name="name" required maxlength="255" autocomplete="name" placeholder="Enter customer name"></label>
        <div class="customer-contact-fields"><label class="field"><span>Phone <span class="optional-label">optional</span></span><input name="phone" type="tel" maxlength="255" autocomplete="tel" placeholder="Phone number"></label><label class="field"><span>Email <span class="optional-label">optional</span></span><input name="email" type="email" maxlength="255" autocomplete="email" placeholder="Email address"></label></div>
        <label class="field"><span>Address <span class="optional-label">optional</span></span><textarea name="address" rows="2" maxlength="2000" autocomplete="street-address" placeholder="Customer address"></textarea></label>
        <div class="customer-popup-opening"><label class="field"><span>Old balance (due)</span><input name="opening_due" type="number" inputmode="decimal" min="0" max="999999999" step="0.01" value="0.00" aria-label="Old balance (due)"><small>Amount this customer already owes. Leave 0 if nothing is due.</small></label></div>
        <div class="customer-dialog-actions"><button class="btn secondary" id="cancel-customer" type="button">Cancel</button><button class="btn primary" id="save-customer" type="submit"><x-icon name="check"/>Save & select customer</button></div>
    </form>
</dialog>
@endcan
@unless(isset($editingSale))@include('pos.partials.checkout-customer')@endunless
<dialog id="payment-dialog" class="payment-dialog split-payment-dialog" aria-labelledby="payment-title">
    <div class="modal-heading"><div><span class="eyebrow">FINISH YOUR ORDER</span><h2 id="payment-title">{{ isset($editingSale)?'Review revised payment':'Take payment' }}</h2></div><button class="icon-button" id="close-payment" type="button" aria-label="Close payment"><x-icon name="x"/></button></div>
    <div class="checkout-layout">
        <section class="checkout-methods">
            <div class="payment-order"><span>{{ isset($editingSale)?'Revised bill total':'Bill total' }}</span><strong id="payment-base">0.00</strong></div><div id="payment-bill-discount" class="payment-bill-discount" hidden><span>Bill discount applied</span><strong id="payment-bill-discount-amount"></strong></div>
            @isset($editingSale)<div class="edit-payment-reconciliation"><h3>Payment adjustment</h3><p>These are the revised invoice payments. Only collect or refund the difference shown below.</p><div id="edit-payment-adjustments"></div></div>@endisset
            <div class="payment-mode" role="group" aria-label="Payment mode"><button type="button" id="single-payment" class="selected" aria-pressed="true">Single payment</button><button type="button" id="split-payment" aria-pressed="false"><x-icon name="plus"/>Split payment</button></div>
            @unless(isset($editingSale))<button class="text-link payment-leave-unpaid" id="leave-unpaid" type="button"><x-icon name="history" :size="16"/>No payment · leave bill due</button>@endunless
            <p id="payment-help" class="muted">Choose how the customer wants to pay.</p>
            <div class="payment-options">@foreach($methods as $method)<button type="button" class="payment-option" data-method-id="{{ $method->id }}" data-method-type="{{ $method->type }}" data-method-name="{{ $method->name }}" aria-pressed="false"><x-icon :name="match($method->type){'CASH'=>'banknote','CARD'=>'credit-card','QR'=>'qr-code',default=>'landmark'}"/><span>{{ $method->name }}</span><span class="method-check" aria-hidden="true">✓</span></button>@endforeach</div>
            @if($methods->isEmpty())<div class="notice error">No active payment methods. Ask an administrator to enable one.</div>@endif
            <div id="payment-lines" class="payment-lines"></div>
            <div id="payment-error" class="notice error" role="alert" hidden></div>
            <button type="button" id="review-payment" class="text-link">Recalculate amounts</button>
        </section>
        <aside class="checkout-keypad">
            <div class="collection-summary"><div><span>Customer fees</span><strong id="payment-fees">0.00</strong></div><div class="collection-total"><span>{{ isset($editingSale)?'Revised payable':'Amount to collect' }}</span><strong id="payment-payable">—</strong></div><div><span>Remaining to allocate</span><strong id="payment-unallocated">0.00</strong></div><div><span>{{ isset($editingSale)?'Revised received':'Received' }}</span><strong id="payment-received">0.00</strong></div><div class="balance-line"><span>Balance due</span><strong id="payment-balance">0.00</strong></div><div class="change-line"><span>{{ isset($editingSale)?'Revised cash change':'Cash change' }}</span><strong id="cash-change">0.00</strong></div></div>
            <div id="keypad-controls"><div class="keypad-heading"><span id="keypad-label">Select an amount to enter</span><button type="button" id="exact-payment" class="text-link">Exact amount</button></div>
            <div class="touch-keypad" role="group" aria-label="Payment amount keypad">@foreach(['7','8','9','4','5','6','1','2','3','.','0','backspace'] as $key)<button type="button" data-key="{{ $key }}" @if($key==='backspace')aria-label="Delete last digit"@endif>{{ $key==='backspace'?'⌫':$key }}</button>@endforeach</div>
            <div class="keypad-tools"><button type="button" id="clear-amount">Clear amount</button><button type="button" id="use-balance">Use remaining</button></div>
            <div id="quick-cash" class="quick-cash"></div></div>
            <button type="button" id="confirm-payment" class="btn primary w-full" disabled><x-icon name="check"/>{{ isset($editingSale)?'Save changes':'Complete sale' }}</button>
            @unless(isset($editingSale))<button type="button" id="confirm-due" class="btn due-checkout-button w-full" disabled hidden><x-icon name="history"/>Complete with due</button><p class="muted credit-checkout-note" id="credit-checkout-note" hidden>Unpaid balance is saved to the selected customer.</p>@endunless
        </aside>
    </div>
</dialog>
<dialog id="receipt-dialog" class="receipt-dialog"><div class="modal-heading"><div><span class="eyebrow">SALE COMPLETE</span><h2 id="receipt-invoice">Receipt</h2></div><button class="icon-button" id="close-receipt" type="button" aria-label="Close receipt"><x-icon name="x"/></button></div><iframe id="receipt-frame" title="Sale receipt"></iframe><div class="receipt-actions"><a id="receipt-external" class="btn secondary" target="_blank">Open receipt</a><button class="btn secondary" id="print-receipt" type="button" disabled><x-icon name="printer"/>Print</button><button class="btn primary" id="next-sale" type="button">Next sale</button></div></dialog>
</div>
@endif
@endsection
@if($register)@push('scripts')@vite('resources/js/pos.js')@endpush
@endif
