import { catalogHref, categoryHref } from "./catalog-href";

/** One step of a path: a name and the page it leads to. */
export type Crumb = { name: string; href: string };

/** "Wszystkie produkty" and then the categories of the path (each with the `path` of the API) down to the last one. */
export function crumbsOf(categories: { name: string; path: string }[]): Crumb[] {
  return [{ name: "Wszystkie produkty", href: catalogHref() }, ...categories.map((category) => ({ name: category.name, href: categoryHref(category.path) }))];
}

/** schema.org `BreadcrumbList` of the path with absolute addresses (the page of the last step is itself). */
export function buildBreadcrumbListJsonLd(crumbs: Crumb[], storeUrl: string) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: crumbs.map(({ name, href }, index) => ({ "@type": "ListItem", position: index + 1, name, item: new URL(href, storeUrl).toString() })),
  };
}
