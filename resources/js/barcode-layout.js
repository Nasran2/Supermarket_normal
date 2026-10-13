// Both preview and standalone printing use this check. Never shrink barcode bars.
export function fitAndCheckLabels(root) {
  let overflow = false;
  for (const label of root.querySelectorAll('.barcode-label')) {
    const content = label.querySelector('.barcode-label-content');
    const name = content.querySelector('.barcode-name-shrink');
    if (name) {
      let size = parseFloat(getComputedStyle(name).fontSize);
      while (name.scrollWidth > name.clientWidth + 1 && size > 6) {
        size = Math.max(6, size - 0.25);
        name.style.fontSize = `${size}px`;
      }
      name.style.overflow = 'hidden';
      name.style.textOverflow = 'ellipsis';
      name.style.whiteSpace = 'nowrap';
      name.style.display = 'block';
    }
    const children = [...content.children];
    const height = children.reduce((sum, child) => sum + child.getBoundingClientRect().height, 0);
    if (
      height > content.clientHeight + 1 ||
      children.some(
        (child) =>
          !child.classList.contains('barcode-label-product_name') &&
          child.scrollWidth > child.clientWidth + 1,
      )
    )
      overflow = true;
  }
  return overflow;
}

export function configureOrientation(root) {
  root.querySelector('[data-config="orientation"]')?.addEventListener('change', () => {
    const width = root.querySelector('[data-config="paper_width"]');
    const height = root.querySelector('[data-config="paper_height"]');
    if (height.value && Number(height.value) > 0) {
      const old = width.value;
      width.value = height.value;
      height.value = old;
      width.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
}
