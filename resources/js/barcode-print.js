import { fitAndCheckLabels } from './barcode-layout.js';
const button = document.getElementById('barcode-browser-print');
const check = () => {
  const overflow = fitAndCheckLabels(document);
  document.getElementById('barcode-print-warning').textContent = overflow
    ? 'Some content does not fit within the current label size. Return to Labels and increase dimensions, reduce fonts or hide optional content.'
    : '';
  button.disabled = overflow;
  button.style.opacity = overflow ? '0.5' : '1';
  return !overflow;
};
button.addEventListener('click', () => {
  if (check()) window.print();
});
window.addEventListener('beforeprint', check);
await document.fonts.ready;
check();
