<div class="field full" @if(config('features.bamini'))data-product-name-editor data-default-mode="{{ $settings['product_name_typing_mode'] ?? 'english' }}"@endif>
    @if(config('features.bamini'))
        <label class="product-name-mode">Typing mode
            <select data-product-name-mode aria-label="Product name typing mode">
                <option value="english">English</option>
                <option value="bamini" @selected(($settings['product_name_typing_mode'] ?? 'english') === 'bamini')>Bamini → Tamil</option>
            </select>
        </label>
    @endif
    <label for="product-name">Product name <b class="required">*</b></label>
    <input id="product-name" name="name" value="{{ $nameValue }}" placeholder="{{ $namePlaceholder }}" maxlength="255" required autofocus data-product-name-input>
    @if(config('features.bamini'))
        <small data-product-name-preview hidden lang="ta" role="status" aria-live="polite"></small>
        <small data-product-name-help hidden>Type Bamini above. The Tamil preview will be saved as the product name.</small>
        @once
            @push('scripts')
                @vite('resources/js/product-name.js')
            @endpush
        @endonce
    @endif
</div>
