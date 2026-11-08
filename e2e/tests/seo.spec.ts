import { expect, test, type APIRequestContext } from "@playwright/test";
import { urls } from "../support/urls";

// robots.txt and the sitemap of the store, on the demo catalogue after `make seed` (one chunk of products).

type CategoryNode = { path: string; children: CategoryNode[] };

const locsOf = (xml: string): string[] => [...xml.matchAll(/<loc>([^<]*)<\/loc>/g)].map((match) => match[1]!);
const pathsOf = (tree: CategoryNode[]): string[] => tree.flatMap((node) => [node.path, ...pathsOf(node.children)]);

async function xml(request: APIRequestContext, address: string): Promise<string> {
  const response = await request.get(address);
  expect(response.status(), address).toBe(200);
  expect(response.headers()["content-type"]).toBe("application/xml; charset=utf-8");
  expect(response.headers()["cache-control"]).toBe("public, max-age=300");
  return response.text();
}

test("robots.txt blocks the pages without content, leaves the rest and names the sitemap", async ({ request }) => {
  const response = await request.get("/robots.txt");
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toContain("text/plain");
  const lines = (await response.text()).split("\n").map((line) => line.trim());
  expect(lines.filter((line) => line !== "")).toEqual([
    "User-Agent: *",
    "Disallow: /cart",
    "Disallow: /checkout",
    "Disallow: /konto",
    "Disallow: /auth/",
    "Disallow: /api/",
    `Sitemap: ${urls.store}/sitemap.xml`,
  ]);
});

test("sitemap.xml lists the listing map and one chunk of products", async ({ request }) => {
  const index = await xml(request, "/sitemap.xml");
  expect(index).toContain("<sitemapindex");
  const maps = locsOf(index);
  expect(maps).toHaveLength(2);
  expect(maps[0]).toBe(`${urls.store}/sitemaps/listing.xml`);
  expect(maps[1]).toMatch(new RegExp(`^${urls.store}/sitemaps/products/[A-Za-z0-9_-]+\\.xml$`));
});

test("the listing map has the home page and every category of the tree", async ({ request }) => {
  const tree = (await (await request.get(`${urls.api}/api/v1/categories`)).json()) as CategoryNode[];
  const paths = pathsOf(tree);
  expect(paths.length).toBeGreaterThan(1);

  const listing = await xml(request, "/sitemaps/listing.xml");
  expect(listing).not.toContain("lastmod");
  expect(locsOf(listing)).toEqual([`${urls.store}/`, ...paths.map((path) => `${urls.store}/c/${path}`)]);
});

test("a product from the chunk map is a page that opens", async ({ request, page }) => {
  const chunkMap = locsOf(await xml(request, "/sitemap.xml")).at(1);
  expect(chunkMap).toBeDefined();
  const products = await xml(request, new URL(chunkMap!).pathname);
  const addresses = locsOf(products);
  expect(addresses.length).toBeGreaterThan(1);
  expect(addresses.every((address) => address.startsWith(`${urls.store}/p/`))).toBe(true);
  expect([...products.matchAll(/<lastmod>/g)]).toHaveLength(addresses.length);

  const response = await page.goto(addresses[0]!);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
});

test("a chunk address without .xml or with a key the API refuses is a 404", async ({ request }) => {
  expect((await request.get("/sitemaps/products/eyJmIjpudWxsLCJ0IjpudWxsfQ")).status()).toBe(404);
  expect((await request.get("/sitemaps/products/not-a-key.xml")).status()).toBe(404);
});
