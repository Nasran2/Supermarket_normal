import { baminiToUnicode } from './bamini.js';

export function matchesProductSearch(product, query, bamini = false) {
  const raw = query.toLowerCase();
  const name = (product.name || '').toLowerCase();
  if (`${name} ${product.sku || ''} ${product.barcode || ''}`.toLowerCase().includes(raw)) return true;
  // Interpret only the name alternative as Bamini; codes always use raw input.
  return bamini && name.includes(baminiToUnicode(query).toLowerCase());
}
