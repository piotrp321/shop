const formatters = new Map<string, Intl.NumberFormat>();

/**
 * Shows a gross price from the API for a customer in Poland. `gross` is in grosze (an integer); the division by 100
 * only turns it into the unit `Intl` formats, the amount itself is never computed here.
 */
export function formatPrice(gross: number, currency: string): string {
  let formatter = formatters.get(currency);
  if (formatter === undefined) {
    formatter = new Intl.NumberFormat("pl-PL", { style: "currency", currency });
    formatters.set(currency, formatter);
  }
  return formatter.format(gross / 100);
}
