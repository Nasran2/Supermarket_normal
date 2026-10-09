export function exactBarcode(products, query) {
  const value = String(query).trim().toLowerCase();
  return value ? products.find(p => p.barcode && String(p.barcode).toLowerCase() === value) : undefined;
}
export function needsStockRow(product) {
  const quantity = Number(product.quantity || 0);
  return product.mode === 'REMOVE' ? quantity > 0 : product.mode === 'SET' && quantity < Number(product.expected_stock);
}
export function singleStockRow(product) {
  return needsStockRow(product) && product.layers?.length === 1 ? String(product.layers[0].id) : '';
}
