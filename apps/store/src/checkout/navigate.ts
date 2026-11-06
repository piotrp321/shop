/** Leaves the store for `url` (the page of a payment provider). A seam: tests replace it, jsdom cannot navigate. */
export function navigateTo(url: string): void {
  window.location.assign(url);
}
