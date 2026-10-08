function scaled(value, precision) {
  const text = String(value ?? '').trim().replace(/^\./, '0.');
  if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(text)) return 0n;
  const [whole, fraction = ''] = text.split('.');
  return BigInt(whole + fraction.padEnd(precision, '0'));
}
export const moneyCents = (value) => scaled(value, 2);
export const lineCostCents = (quantity, cost) => (scaled(quantity, 3) * moneyCents(cost) + 500n) / 1000n;
export const amountValue = (cents) => `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
export function displayCents(cents) {
  const sign = cents < 0n ? '−' : '';
  const [whole, fraction] = amountValue(cents < 0n ? -cents : cents).split('.');
  return sign + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + fraction;
}
export function paymentBalance(total, given) {
  const paid = given < total ? given : total;
  return {paid, due: total - paid, change: given > total ? given - total : 0n, status: paid >= total ? 'Paid' : paid > 0n ? 'Partial' : 'Unpaid'};
}
export function allocateCharges(values, charges) {
  const subtotal = values.reduce((sum,value)=>sum+value,0n);
  const denominator = subtotal || BigInt(values.length || 1);
  let weight = 0n, previous = 0n;
  return values.map(value=>{ weight += subtotal ? value : 1n; const cumulative=(charges*weight*2n+denominator)/(denominator*2n); const part=cumulative-previous; previous=cumulative; return part; });
}
