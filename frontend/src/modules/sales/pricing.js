// Match the API's round-half-up calculation without binary floating-point
// ambiguity at half cents (quantities allow three decimal places).
export function lineAmount(line) {
  const price = Number(line.price), qty = Number(line.qty), value = Number(line.discount_value || 0);
  if (![price, qty, value].every(Number.isFinite)) return NaN;
  const gross = (BigInt(Math.round(price * 100)) * BigInt(Math.round(qty * 1000)) + 500n) / 1000n;
  const discount = line.discount_type === 'percentage'
    ? (gross * BigInt(Math.round(value * 100)) + 5000n) / 10000n
    : BigInt(Math.round(value * 100));
  return Number(gross - discount) / 100;
}

export function invoiceAmount(lines, delivery = 0, services = []) {
  return (lines.reduce((sum, line) => sum + Math.round(lineAmount(line) * 100), 0)
    + Math.round(Number(delivery || 0) * 100)
    + services.reduce((sum, charge) => sum + Math.round(Number(charge.amount) * 100), 0)) / 100;
}

export function posTotals(lines, {rule=null,billType='fixed',billValue=0,redeem=0,pointValue=0}={}) {
  const sub=invoiceAmount(lines.map(l=>({...l,discount_value:0})));
  const base=invoiceAmount(lines);
  const productDiscount=Math.round((sub-base)*100)/100;
  const bill=rule && sub>=Number(rule.min_total||0)
    ? (rule.type==='percent'?Math.round((base-lineAmount({price:base,qty:1,discount_type:'percentage',discount_value:Number(rule.value)}))*100)/100:Math.min(base,Number(rule.value)))
    : (billType==='percentage'?Math.round((base-lineAmount({price:base,qty:1,discount_type:'percentage',discount_value:Number(billValue)}))*100)/100:Number(billValue));
  const loyalty=Math.min(Math.round(redeem*pointValue*100)/100,Math.max(0,base-bill));
  const total=Math.round((base-bill-loyalty)*100)/100;
  return {sub,productDiscount,bill,loyalty,disc:Math.round((productDiscount+bill+loyalty)*100)/100,total};
}
