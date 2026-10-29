/** Catalogue URL for a filter and page. The first page and the whole catalogue leave their parameter out. */
export function catalogHref({ category, page }: { category?: string; page?: number }): string {
  const query = new URLSearchParams();
  if (category) query.set("category", category);
  if (page && page > 1) query.set("page", String(page));
  const search = query.toString();
  return search ? `/?${search}` : "/";
}
