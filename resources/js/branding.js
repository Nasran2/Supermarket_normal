const form = document.querySelector('[data-branding-form]');
if (form) {
  const preview = form.querySelector('.branding-preview');
  const defaults = JSON.parse(form.querySelector('[data-branding-defaults]').textContent);
  const field = (name) => form.elements.namedItem(name);
  let imageUrl = null;
  const foreground = (hex) => {
    const rgb = hex.slice(1).match(/../g).map((v) => {
      const c = parseInt(v, 16) / 255;
      return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722 > 0.179 ? '#111827' : '#ffffff';
  };
  const update = () => {
    const name = field('brand_name').value.trim() || form.dataset.businessName;
    const texts = {
      brand_name: name,
      brand_subtitle: field('brand_subtitle').value,
      login_brand_text: field('login_brand_text').value.trim() || `${name.toUpperCase()} POS`,
      login_title: field('login_title').value,
      login_description: field('login_description').value,
    };
    for (const [key, value] of Object.entries(texts)) {
      preview.querySelector(`[data-preview="${key}"]`).textContent = value;
    }
    for (const [key, variable] of Object.entries({
      theme_color: '--green', brand_name_color: '--brand-name-color', brand_subtitle_color: '--brand-subtitle-color',
      login_accent_color: '--login-accent', login_background_color: '--login-background',
      login_title_color: '--login-title-color', login_description_color: '--login-description-color',
    })) {
      const color = field(key).value;
      if (!/^#[0-9a-fA-F]{6}$/.test(color)) continue;
      preview.style.setProperty(variable, color);
      form.querySelector(`[data-color-picker="${key}"]`).value = color;
      if (key === 'theme_color') preview.style.setProperty('--accent-foreground', foreground(color));
      if (key === 'login_accent_color') preview.style.setProperty('--login-foreground', foreground(color));
    }
    const mode = field('login_icon_mode').value;
    const custom = field('remove_login_logo').value === '1' ? '' : (imageUrl || form.dataset.loginLogo);
    const source = mode === 'business-logo' ? form.dataset.businessLogo : (mode === 'custom-logo' ? custom : '');
    const img = preview.querySelector('[data-preview-image]');
    img.hidden = !source;
    if (source) img.src = source;
    preview.querySelector('.branding-preview-icon').hidden = !!source;
    preview.querySelectorAll('[data-preview-icon]').forEach((el) => {
      el.hidden = el.dataset.previewIcon !== field('login_icon').value;
    });
    form.querySelector('.branding-upload-current').hidden = !form.dataset.loginLogo || field('remove_login_logo').value === '1';
  };
  form.querySelectorAll('[data-color-picker]').forEach((picker) => {
    picker.addEventListener('input', () => { field(picker.dataset.colorPicker).value = picker.value; });
  });
  form.querySelectorAll('[name="login_icon"]').forEach((radio) => {
    radio.addEventListener('change', () => { field('login_icon_mode').value = 'icon'; });
  });
  field('login_logo').addEventListener('change', () => {
    if (imageUrl) URL.revokeObjectURL(imageUrl);
    const file = field('login_logo').files[0];
    imageUrl = file ? URL.createObjectURL(file) : null;
    if (file) { field('remove_login_logo').value = '0'; field('login_icon_mode').value = 'custom-logo'; }
    update();
  });
  form.querySelector('[data-remove-login-logo]').addEventListener('click', () => {
    field('remove_login_logo').value = '1';
    field('login_logo').value = '';
    if (imageUrl) URL.revokeObjectURL(imageUrl);
    imageUrl = null;
    update();
  });
  form.querySelector('[data-branding-reset]').addEventListener('click', () => {
    for (const [key, value] of Object.entries(defaults)) {
      if (key !== 'login_logo') field(key).value = value ?? '';
    }
    update();
  });
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
}
