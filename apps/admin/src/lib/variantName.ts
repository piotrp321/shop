/** The name of a variant in windows, toasts and button names: the product, and the label unless it is the default variant. */
export function variantName(row: { name: string; label: string }): string {
  return row.label === "" ? row.name : `${row.name} / ${row.label}`;
}
