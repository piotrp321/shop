import type { components } from "@/api/schema";
import { categoryHref } from "@/catalog/catalog-href";
import { DEPARTMENT_IMAGE_IDS } from "./home-content";

type CategoryNode = components["schemas"]["CategoryNode"];

export type DepartmentTile = { id: string; name: string; href: string; imageId: string | undefined };

/** A tile for every root category of the tree, in the order of the tree; the photo is picked by the slug of the category. */
export function departmentTiles(tree: CategoryNode[]): DepartmentTile[] {
  return tree.map((category) => ({
    id: category.id,
    name: category.name,
    href: categoryHref(category.path),
    imageId: DEPARTMENT_IMAGE_IDS[category.slug],
  }));
}
