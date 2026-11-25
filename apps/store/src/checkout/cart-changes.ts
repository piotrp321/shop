import type { CartView } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";

type Line = CartView["lines"][number];

/** The name of a line whose variant Catalog does not know in either summary. */
const UNKNOWN_PRODUCT = "Nieznany produkt";

/**
 * What changed between the summary the checkout showed and the one read after a refusal of the order, one Polish
 * sentence per change, in the order of the summary: the lines shown (another quantity, gone, another value at the same
 * quantity), the new lines, the discount of the code, the chosen delivery method (its cost, or that it is gone) and the
 * total to pay of that method. Both summaries are the API's: the function compares the values they show and computes
 * none. Without a chosen method nothing is told of the delivery and of the total, and nothing of the delivery of a cart
 * that has nothing to buy any more (it shows no summary).
 */
export function cartChanges(shown: CartView, current: CartView, chosenMethodId: string | null): string[] {
  const currency = current.totals.currency;
  const price = (gross: number) => formatPrice(gross, currency);
  const changes: string[] = [];
  const currentLines = new Map(current.lines.map((line) => [line.variantId, line]));
  const shownIds = new Set(shown.lines.map((line) => line.variantId));

  for (const before of shown.lines) {
    const after = currentLines.get(before.variantId);
    const name = lineName(before, after);
    if (after === undefined) {
      changes.push(`${name}: usunięto z koszyka`);
    } else if (after.quantity !== before.quantity) {
      changes.push(`${name}: było ${before.quantity} szt., jest ${after.quantity} szt.`);
    } else if (before.lineTotal !== null && after.lineTotal !== null && before.lineTotal.gross !== after.lineTotal.gross) {
      changes.push(`${name}: wartość była ${price(before.lineTotal.gross)}, jest ${price(after.lineTotal.gross)}`);
    }
  }
  for (const added of current.lines) {
    if (!shownIds.has(added.variantId)) changes.push(`${lineName(added)}: dodano, ${added.quantity} szt.`);
  }

  if (shown.totals.discountGross !== current.totals.discountGross) {
    changes.push(`Rabat: był ${price(shown.totals.discountGross)}, jest ${price(current.totals.discountGross)}`);
  }

  if (chosenMethodId === null || !current.delivery) return changes;
  const was = shown.delivery?.options.find((option) => option.methodId === chosenMethodId);
  if (was === undefined) return changes;
  const now = current.delivery.options.find((option) => option.methodId === chosenMethodId);
  if (now === undefined) {
    changes.push(`Dostawa „${was.name}” nie jest już dostępna. Wybierz sposób dostawy.`);
    return changes;
  }
  if (was.costGross !== now.costGross) {
    changes.push(`Dostawa „${now.name}”: była ${price(was.costGross)}, jest ${price(now.costGross)}`);
  }
  if (was.totalGross !== now.totalGross) {
    changes.push(`Do zapłaty: było ${price(was.totalGross)}, jest ${price(now.totalGross)}`);
  }
  return changes;
}

/** The product with its variant, as the cart names it; from whichever summary still knows the product. */
function lineName(line: Line, other?: Line): string {
  const product = line.product ?? other?.product ?? null;
  if (product === null || product.name === "") return UNKNOWN_PRODUCT;
  return product.variantLabel === "" ? product.name : `${product.name}, ${product.variantLabel}`;
}
