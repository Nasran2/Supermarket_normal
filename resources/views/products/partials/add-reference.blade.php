@can($reference.'.create')
<dialog id="product-{{ $reference }}-dialog" class="customer-dialog" data-product-reference-dialog data-select="{{ $selectId }}">
    <div class="modal-heading">
        <div><span class="eyebrow">PRODUCT INFORMATION</span><h2>Add {{ $singular }}</h2></div>
        <button type="button" class="icon-button" data-close-product-reference aria-label="Close add {{ $singular }}"><x-icon name="x"/></button>
    </div>
    <form class="customer-dialog-body" method="POST" action="{{ route('manage.store',$reference) }}">
        @csrf
        <p class="notice error" data-reference-error role="alert" hidden></p>
        <label class="field"><span>{{ ucfirst($singular) }} name <b class="required">*</b></span><input name="name" maxlength="255" required autocomplete="off"></label>
        @if($reference==='suppliers')
            <input type="hidden" name="active" value="1">
            <label class="field"><span>Phone <small>optional</small></span><input name="phone" type="tel" maxlength="255"></label>
            <label class="field"><span>Email <small>optional</small></span><input name="email" type="email" maxlength="255"></label>
            <label class="field"><span>Address <small>optional</small></span><textarea name="address" rows="2" maxlength="2000"></textarea></label>
        @endif
        <p class="muted mt-4">This {{ $singular }} will be saved and selected for your product.</p>
        <div class="customer-dialog-actions"><button type="button" class="btn secondary" data-close-product-reference>Cancel</button><button type="submit" class="btn primary"><x-icon name="check"/>Save {{ $singular }}</button></div>
    </form>
</dialog>
@endcan
