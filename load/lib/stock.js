// The available units of the variants, for the scenarios whose cart step raises a quantity. The public API never tells the units of a variant, and the
// cart answers 409 (`Nie mamy tylu sztuk tego produktu.`) to a raise above them, so the scenario of the panel (load/panel.js) takes the units from the
// admin stock list (GET /api/v1/admin/stock?productId=...) and raises only the quantity of the variants that have enough of them.

// The available units by variant id, from the body of GET /api/v1/admin/stock (items, each with its variants and their `stock.available`).
export function availableOf(stockPage) {
  const available = {};
  for (const item of (stockPage && stockPage.items) || []) {
    for (const variant of item.variants || []) {
      available[variant.variantId] = variant.stock.available;
    }
  }
  return available;
}

// The variants with at least `units` available; one the stock list does not know has none.
export function variantsWithUnits(variants, available, units) {
  return variants.filter((variant) => (available[variant.variantId] || 0) >= units);
}
