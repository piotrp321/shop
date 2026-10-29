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
