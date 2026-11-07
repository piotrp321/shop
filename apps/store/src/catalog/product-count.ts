/**
 * The number of products with the Polish form of "produkt": 1 produkt, 2-4 produkty (also 22-24, 32-34, ...),
 * and produktów for the rest, including 12-14.
 */
export function formatProductCount(count: number): string {
  const lastDigit = count % 10;
  const lastTwoDigits = count % 100;
  if (count === 1) return "1 produkt";
  if (lastDigit >= 2 && lastDigit <= 4 && !(lastTwoDigits >= 12 && lastTwoDigits <= 14)) return `${count} produkty`;
  return `${count} produktów`;
}
