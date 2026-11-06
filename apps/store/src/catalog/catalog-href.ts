/** Catalogue URL (all products) for the page. The first page leaves its parameter out. */
export function catalogHref({ page }: { page?: number } = {}): string {
  return page && page > 1 ? `/?page=${page}` : "/";
}

/** Category page URL for the slug path from the API (`bieganie/buty`); later pages add `?page=n`. */
export function categoryHref(path: string, page?: number): string {
  return page && page > 1 ? `/c/${path}?page=${page}` : `/c/${path}`;
}
