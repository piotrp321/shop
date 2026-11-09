import type { MetadataRoute } from "next";

/** The pages without content to index: the cart, the checkout, the account, the login and the route handlers. */
const DISALLOWED = ["/cart", "/checkout", "/konto", "/auth/", "/api/"];

/**
 * The rules of `robots.txt`: everything is allowed except `DISALLOWED`. Pages with `noindex, follow` (a listing with
 * filters, the search) stay allowed on purpose: a robot has to fetch the page to see its `noindex`. The same rules apply
 * in every environment. `storeUrl` has no trailing slash.
 */
export function buildRobots(storeUrl: string): MetadataRoute.Robots {
  return {
    rules: { userAgent: "*", disallow: DISALLOWED },
    sitemap: `${storeUrl.replace(/\/+$/, "")}/sitemap.xml`,
  };
}
