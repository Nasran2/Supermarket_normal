export function draftStore(storage, key) {
  return {
    read() {
      const raw = storage.getItem(key);
      if (!raw) return null;
      const value = JSON.parse(raw);
      if (
        value.version !== 1 ||
        !Array.isArray(value.items) ||
        value.items.length > 300 ||
        !/^[0-9a-f-]{36}$/i.test(value.token || '')
      )
        throw new Error('Invalid saved cart');
      if (
        value.billDiscount &&
        (!['AMOUNT', 'PERCENT'].includes(value.billDiscount.type) ||
          !/^\d+(?:\.\d{1,2})?$/.test(String(value.billDiscount.value)) ||
          Number(value.billDiscount.value) >
            (value.billDiscount.type === 'PERCENT' ? 100 : 999999999))
      )
        throw new Error('Invalid saved bill discount');
      const ids = new Set();
      for (const item of value.items) {
        if (
          !Number.isSafeInteger(item.id) ||
          item.id < 1 ||
          ids.has(item.id) ||
          !Number.isSafeInteger(item.unit_id) ||
          item.unit_id < 1 ||
          !/^\d+(?:\.\d{1,3})?$/.test(String(item.quantity)) ||
          Number(item.quantity) <= 0 ||
          Number(item.quantity) > 999999 ||
          typeof item.name !== 'string' ||
          item.name.length > 255 ||
          !/^\d+(?:\.\d{1,2})?$/.test(String(item.price)) ||
          !['AMOUNT', 'PERCENT'].includes(item.discount_type) ||
          !/^\d+(?:\.\d{1,2})?$/.test(String(item.discount_value)) ||
          (item.unit_price !== null && !/^\d+(?:\.\d{1,2})?$/.test(String(item.unit_price)))
        )
          throw new Error('Invalid saved item');
        ids.add(item.id);
      }
      return value;
    },
    write(value) {
      storage.setItem(key, JSON.stringify({ ...value, version: 1 }));
    },
    clear() {
      storage.removeItem(key);
    },
  };
}
