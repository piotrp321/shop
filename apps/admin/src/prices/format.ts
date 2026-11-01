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

// Poland is the only market, so times are shown in Polish time whatever the time zone of the browser.
const dateTimeFormatter = new Intl.DateTimeFormat("pl-PL", {
  timeZone: "Europe/Warsaw",
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hourCycle: "h23",
});

/** Shows a moment from the API (RFC 3339) in Polish time; a value that is not a moment is shown as it came. */
export function formatDateTime(moment: string): string {
  const date = new Date(moment);
  return Number.isNaN(date.getTime()) ? moment : dateTimeFormatter.format(date);
}

const sourceLabels: Record<string, string> = {
  staff: "Pracownik",
  seed: "Dane demo",
  tax_reconciliation: "Uzgodnienie podatkowe",
};

/** Names what made a price change; a source the panel does not know is shown as the API sent it. */
export function sourceLabel(source: string): string {
  return sourceLabels[source] ?? source;
}
