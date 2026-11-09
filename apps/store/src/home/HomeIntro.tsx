import type { components } from "@/api/schema";
import { categoryHref } from "@/catalog/catalog-href";
import { departmentTiles } from "./departments";
import { DepartmentTiles } from "./DepartmentTiles";
import { Hero } from "./Hero";
import { HERO } from "./home-content";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * What the home page shows above the listing in the default state: the hero and the tiles of the departments. The tree
 * is the one the header reads; `null` (it could not be read) gives the hero without a link and no tiles, an empty tree no tiles.
 */
export function HomeIntro({ categories }: { categories: CategoryNode[] | null }) {
  const linked = categories?.find((category) => category.slug === HERO.link.slug);
  const tiles = categories === null ? [] : departmentTiles(categories);
  return (
    <>
      <Hero href={linked === undefined ? null : categoryHref(linked.path)} />
      {tiles.length > 0 && <DepartmentTiles tiles={tiles} />}
    </>
  );
}
