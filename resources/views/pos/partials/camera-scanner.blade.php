<dialog id="camera-scanner" class="payment-dialog pos-camera-dialog" aria-labelledby="camera-scanner-title" aria-describedby="camera-scanner-help">
    <div class="modal-heading">
        <h2 id="camera-scanner-title">Scan with Camera</h2>
        <button class="icon-button" type="button" data-camera-close aria-label="Close camera scanner"><x-icon name="x"/></button>
    </div>
    <div class="modal-body pos-camera-body">
        <div class="pos-camera-preview" data-camera-preview hidden>
            <video muted autoplay playsinline aria-hidden="true"></video>
            <div class="pos-camera-frame" aria-hidden="true"></div>
        </div>
        <p id="camera-scanner-help" class="muted text-sm">Center one barcode horizontally. If it looks blurry, add light and adjust the distance. Move it out of the frame before scanning it again.</p>
        <p class="pos-camera-status" data-camera-status role="status" aria-live="polite" aria-atomic="true"></p>
        <div class="pos-camera-actions">
            <button class="btn secondary" type="button" data-camera-switch hidden><x-icon name="switch-camera"/>Switch camera</button>
            <button class="btn secondary" type="button" data-camera-torch aria-pressed="false" hidden><x-icon name="flashlight"/>Torch</button>
            <label class="pos-camera-zoom" data-camera-zoom hidden>Zoom <input type="range"></label>
            <button class="btn secondary" type="button" data-camera-retry hidden>Retry camera</button>
        </div>
    </div>
</dialog>
