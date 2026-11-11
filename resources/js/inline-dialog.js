let activeDialog = null;

function ensureDialog() {
  if (activeDialog) return activeDialog;

  const dialog = document.createElement('dialog');
  dialog.className = 'card inline-confirm-dialog';
  dialog.setAttribute('aria-modal', 'true');
  dialog.innerHTML = `
    <div class="inline-confirm-box">
      <div class="inline-confirm-header">
        <div class="inline-confirm-icon" data-confirm-icon>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
        </div>
        <div class="inline-confirm-copy">
          <h3 data-confirm-title>Please confirm</h3>
          <p data-confirm-message></p>
        </div>
      </div>
      <div class="inline-confirm-actions">
        <button type="button" class="btn secondary" data-confirm-cancel>Cancel</button>
        <button type="button" class="btn primary" data-confirm-ok>Confirm</button>
      </div>
    </div>
  `;
  document.body.appendChild(dialog);
  activeDialog = dialog;
  return dialog;
}

/**
 * Show an in-page modal confirmation dialog instead of window.confirm.
 *
 * @param {string|{title?: string, message: string, confirmText?: string, cancelText?: string, danger?: boolean}} options
 * @returns {Promise<boolean>}
 */
export function showInlineConfirm(options) {
  const opts = typeof options === 'string' ? { message: options } : options;
  const dialog = ensureDialog();
  const titleEl = dialog.querySelector('[data-confirm-title]');
  const msgEl = dialog.querySelector('[data-confirm-message]');
  const cancelBtn = dialog.querySelector('[data-confirm-cancel]');
  const okBtn = dialog.querySelector('[data-confirm-ok]');
  const iconEl = dialog.querySelector('[data-confirm-icon]');

  const isDanger = Boolean(opts.danger);
  titleEl.textContent = opts.title || (isDanger ? 'Confirm removal' : 'Please confirm');
  msgEl.textContent = opts.message || '';
  cancelBtn.textContent = opts.cancelText || 'Cancel';
  okBtn.textContent = opts.confirmText || 'Confirm';

  okBtn.className = isDanger ? 'btn btn-danger-solid' : 'btn primary';
  iconEl.className = 'inline-confirm-icon ' + (isDanger ? 'is-danger' : 'is-info');

  return new Promise((resolve) => {
    let settled = false;
    const finish = (result) => {
      if (settled) return;
      settled = true;
      cancelBtn.removeEventListener('click', onCancel);
      okBtn.removeEventListener('click', onOk);
      dialog.removeEventListener('cancel', onCancel);
      dialog.removeEventListener('click', onBackdrop);
      if (dialog.open) dialog.close();
      resolve(result);
    };

    const onCancel = (e) => {
      e?.preventDefault?.();
      finish(false);
    };
    const onOk = () => finish(true);
    const onBackdrop = (e) => {
      if (e.target === dialog) finish(false);
    };

    cancelBtn.addEventListener('click', onCancel);
    okBtn.addEventListener('click', onOk);
    dialog.addEventListener('cancel', onCancel);
    dialog.addEventListener('click', onBackdrop);

    dialog.showModal();
    requestAnimationFrame(() => okBtn.focus());
  });
}

/**
 * Bind an inline confirm modal onto a form submit event, re-submitting cleanly once confirmed.
 *
 * @param {HTMLFormElement} form
 * @param {Event} event
 * @param {string|{title?: string, message: string, confirmText?: string, danger?: boolean}} options
 * @returns {boolean} True if intercepted for confirmation modal.
 */
export function interceptWithInlineConfirm(form, event, options) {
  if (form.dataset.confirmBypass === '1') {
    delete form.dataset.confirmBypass;
    return false;
  }

  event.preventDefault();
  event.stopImmediatePropagation?.();
  const submitter = event.submitter || null;

  showInlineConfirm(options).then((confirmed) => {
    if (!confirmed) return;
    form.dataset.confirmBypass = '1';
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter && form.contains(submitter) ? submitter : undefined);
    } else {
      form.submit();
    }
  });

  return true;
}

