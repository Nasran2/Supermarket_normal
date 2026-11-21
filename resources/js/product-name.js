import { baminiToUnicode } from './bamini.js';

// Remember which characters were actually typed in Bamini mode. Existing names
// and text entered in English mode remain literal, even when modes are changed.
export function createProductNameState(initial = '', initialMode = 'english') {
  let raw = initial;
  let literal = Array(raw.length).fill(true);
  let mode = initialMode;
  let displayed = initial;
  const converted = () => {
    let value = '';
    for (let start = 0; start < raw.length;) {
      let end = start + 1;
      while (end < raw.length && literal[end] === literal[start]) end++;
      const part = raw.slice(start, end);
      value += literal[start] ? part : baminiToUnicode(part);
      start = end;
    }
    return value;
  };
  return {
    get value() { return mode === 'english' ? displayed : converted(); },
    get text() { return displayed; },
    prefill(value) {
      raw = displayed = value;
      literal = Array(raw.length).fill(true);
    },
    switchMode(next) {
      if (next === mode) return;
      if (next === 'english') displayed = converted();
      else displayed = raw;
      mode = next;
    },
    input(next, selection) {
      if (mode === 'english') {
        this.prefill(next);
        return;
      }
      if (selection?.start === selection?.end && selection?.inputType?.startsWith('delete')) {
        const removed = raw.length - next.length;
        if (selection.inputType.endsWith('Backward')) selection = { start: selection.start - removed, end: selection.end };
        else selection = { start: selection.start, end: selection.end + removed };
      }
      let start = 0, end = raw.length, nextEnd = next.length;
      // beforeinput supplies the actual edit range, including repeated letters.
      if (selection && next.startsWith(raw.slice(0, selection.start)) &&
          next.endsWith(raw.slice(selection.end)) && next.length >= raw.length - (selection.end - selection.start)) {
        start = selection.start;
        end = selection.end;
        nextEnd = next.length - (raw.length - end);
      } else {
        while (start < raw.length && start < next.length && raw[start] === next[start]) start++;
        while (end > start && nextEnd > start && raw[end - 1] === next[nextEnd - 1]) { end--; nextEnd--; }
      }
      literal.splice(start, end - start, ...Array(nextEnd - start).fill(false));
      raw = displayed = next;
    },
  };
}

export function initProductNames(root = document) {
  root.querySelectorAll('[data-product-name-editor]').forEach((editor) => {
    if (editor.dataset.initialized) return;
    editor.dataset.initialized = '1';
    const input = editor.querySelector('[data-product-name-input]');
    const mode = editor.querySelector('[data-product-name-mode]');
    const preview = editor.querySelector('[data-product-name-preview]');
    const help = editor.querySelector('[data-product-name-help]');
    const saved = document.createElement('input');
    saved.type = 'hidden';
    saved.name = input.name;
    saved.defaultValue = input.defaultValue;
    input.removeAttribute('name');
    input.after(saved);
    const state = createProductNameState(input.value, mode.value);
    let selection;
    const render = () => {
      saved.value = state.value;
      const bamini = mode.value === 'bamini';
      preview.hidden = help.hidden = !bamini;
      preview.textContent = state.value;
      if (bamini) input.removeAttribute('maxlength');
      else input.maxLength = 255;
      input.setCustomValidity(Array.from(state.value).length > 255 ? 'Product name must be 255 characters or fewer.' : '');
    };
    input.addEventListener('beforeinput', (event) => {
      selection = { start: input.selectionStart, end: input.selectionEnd, inputType: event.inputType };
    });
    input.addEventListener('input', () => {
      state.input(input.value, selection);
      selection = undefined;
      render();
    });
    input.addEventListener('product-name:prefill', () => { state.prefill(input.value); render(); });
    mode.addEventListener('change', () => {
      // Also capture browser autofill before switching modes.
      if (input.value !== state.text) state.input(input.value);
      state.switchMode(mode.value);
      input.value = state.text;
      selection = undefined;
      render();
    });
    input.form.addEventListener('reset', () => queueMicrotask(() => {
      mode.value = editor.dataset.defaultMode;
      state.prefill(input.defaultValue);
      state.switchMode(mode.value);
      input.value = state.text;
      selection = undefined;
      render();
    }));
    // Covers native submission and the Quick Add AJAX FormData path.
    input.form.addEventListener('formdata', (event) => {
      if (input.value !== state.text) state.input(input.value);
      render();
      event.formData.set(saved.name, state.value);
    });
    render();
  });
}

if (typeof document !== 'undefined') initProductNames();
