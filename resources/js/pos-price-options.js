// Depleted deliveries never require an extra price selection at checkout.
export function availablePriceOptions(product) {
  return (product?.price_options || []).filter((group) =>
    Number.isFinite(Number(group.quantity)) && Number(group.quantity) > 0 &&
    Number.isFinite(Number(group.stock_price)) && Number(group.stock_price) >= 0,
  );
}

export const stockChoiceKey = (item) =>
  `${item.id}:${item.stock_layer_id ?? ''}:${item.stock_price ?? ''}:${item.expires_at ?? ''}:${item.unit_id}`;

const priceKey = (price) => {
  const [whole, fraction = ''] = String(price).split('.');
  return `${whole.replace(/^0+(?=\d)/, '')}.${fraction.padEnd(2, '0')}`;
};

// New cart lines choose a selling price; checkout allocates its deliveries by FIFO.
export function sellingPriceOptions(product) {
  if (product?.track_expiry) return availablePriceOptions(product);
  const groups = new Map();
  for (const option of availablePriceOptions(product)) {
    const key = priceKey(option.stock_price);
    const quantity = BigInt(Number(option.quantity).toFixed(3).replace('.', ''));
    const group = groups.get(key);
    if (group) group.quantity += quantity;
    else groups.set(key, {
      stock_price: key, quantity, stock_layer_id: null, units: option.units,
    });
  }
  return [...groups.values()].map((group) => ({
    ...group,
    quantity: `${group.quantity / 1000n}.${String(group.quantity % 1000n).padStart(3, '0')}`,
  }));
}

export function priceOptionForItem(product, item) {
  const options = availablePriceOptions(product);
  if (product?.track_expiry) return options.find(option => priceKey(option.stock_price) === priceKey(item.stock_price) && (option.expires_at ?? null) === (item.expires_at ?? null));
  if (item.stock_layer_id != null) {
    return options.find((option) => option.stock_layer_id === item.stock_layer_id &&
      (item.stock_price == null || priceKey(option.stock_price) === priceKey(item.stock_price)));
  }
  if (item.stock_price != null) {
    return sellingPriceOptions(product).find((option) => priceKey(option.stock_price) === priceKey(item.stock_price));
  }
  const prices = sellingPriceOptions(product);
  return prices.length === 1 ? prices[0] : undefined;
}
