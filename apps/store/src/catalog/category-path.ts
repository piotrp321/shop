import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];

/** Categories from the root down to the one with `id`, or an empty array when the tree has no such category. */
export function findCategoryPath(categories: CategoryNode[], id: string | undefined): CategoryNode[] {
  if (!id) return [];
  for (const category of categories) {
    if (category.id === id) return [category];
    const below = findCategoryPath(category.children, id);
    if (below.length > 0) return [category, ...below];
  }
  return [];
}

/**
 * The categories from the root down to the one that the slugs lead to (`/c/<slug>/<slug>`), or an empty array when
 * the path is empty or any slug does not exist at its level.
 */
export function resolveCategoryPath(categories: CategoryNode[], slugs: string[]): CategoryNode[] {
  const chain: CategoryNode[] = [];
  let level = categories;
  for (const slug of slugs) {
    const found = level.find((category) => category.slug === slug);
    if (found === undefined) return [];
    chain.push(found);
    level = found.children;
  }
  return chain;
}
