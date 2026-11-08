const XML_HEAD = '<?xml version="1.0" encoding="UTF-8"?>';
const NAMESPACE = "http://www.sitemaps.org/schemas/sitemap/0.9";

const ENTITIES: Record<string, string> = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" };

/** Escapes the five characters that XML text may not contain as they are. */
export function escapeXml(text: string): string {
  return text.replace(/[&<>"']/g, (character) => ENTITIES[character]);
}

/** A `sitemapindex` that lists the given absolute addresses of sitemaps, in the given order. */
export function buildSitemapIndex(locations: string[]): string {
  const entries = locations.map((location) => `  <sitemap><loc>${escapeXml(location)}</loc></sitemap>\n`);
  return `${XML_HEAD}\n<sitemapindex xmlns="${NAMESPACE}">\n${entries.join("")}</sitemapindex>\n`;
}

export type UrlSetEntry = { loc: string; lastmod?: string };

/** A `urlset` of the given absolute addresses, in the given order; `lastmod` only where an entry has one. */
export function buildUrlSet(entries: UrlSetEntry[]): string {
  const urls = entries.map(
    ({ loc, lastmod }) =>
      `  <url><loc>${escapeXml(loc)}</loc>${lastmod === undefined ? "" : `<lastmod>${escapeXml(lastmod)}</lastmod>`}</url>\n`,
  );
  return `${XML_HEAD}\n<urlset xmlns="${NAMESPACE}">\n${urls.join("")}</urlset>\n`;
}
