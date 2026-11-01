const moneyFormatters = new Map<string, Intl.NumberFormat>();
const rateFormatter = new Intl.NumberFormat("pl-PL", { style: "percent", maximumFractionDigits: 2 });

/**
 * Shows an amount from the API. `amount` is in grosze (an integer); the division by 100 only turns it into the unit
 * `Intl` formats, no amount is ever computed here.
 */
export function formatMoney(amount: number, currency: string): string {
  let formatter = moneyFormatters.get(currency);
  if (formatter === undefined) {
    formatter = new Intl.NumberFormat("pl-PL", { style: "currency", currency });
    moneyFormatters.set(currency, formatter);
  }
  return formatter.format(amount / 100);
}

/** Shows a VAT rate from the API, given in basis points (2300 is 23%). */
export function formatRate(basisPoints: number): string {
  return rateFormatter.format(basisPoints / 10000);
}
