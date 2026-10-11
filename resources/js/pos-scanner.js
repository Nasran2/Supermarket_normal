export function exactProductMatch(products, code) {
  const value = String(code ?? '').trim();
  if (!value) return undefined;
  return (
    products.find((product) => product.barcode === value) ??
    products.find((product) => product.sku === value)
  );
}

// Each Enter scan keeps its own lookup result, even when another scan arrives first.
export function createProductScanner({ lookup, add, missing, error }) {
  let queue = Promise.resolve();
  return (code, context) => {
    const value = String(code ?? '').trim();
    if (!value) return Promise.resolve(false);
    queue = queue.then(async () => {
      try {
        const product = exactProductMatch(await lookup(value), value);
        if (!product) {
          missing(value, context);
          return false;
        }
        return add(product, context);
      } catch (failure) {
        error(failure, context);
        return false;
      }
    });
    return queue;
  };
}
