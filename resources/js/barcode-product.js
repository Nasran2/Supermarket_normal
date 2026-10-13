const generate = document.querySelector('[data-generate-product-barcode]');
generate?.addEventListener('click', async () => {
  generate.disabled = true;
  const feedback = document.querySelector('[data-barcode-feedback]');
  feedback.textContent = '';
  try {
    const response = await fetch(generate.dataset.url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify({ barcode_type: document.getElementById('product-barcode-type').value }),
    });
    const data = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(data.errors || {})
          .flat()
          .join(' ') ||
          data.message ||
          'Barcode generation failed.',
      );
    document.getElementById('product-barcode').value = data.barcode;
    feedback.textContent = 'Barcode generated. Save the product to assign it.';
  } catch (error) {
    feedback.textContent = error.message;
  } finally {
    generate.disabled = false;
  }
});
