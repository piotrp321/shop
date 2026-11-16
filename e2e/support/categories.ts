import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { urls } from "./urls";
import { waitForBearerToken } from "./staff-login";

// The categories of the screen tests are created by the tests themselves, all with this slug prefix, and removed by it
// at the end: the demo categories are never changed, so the tree of the store is as it was for the files that run after.
export const SLUG_PREFIX = "zz-e2e-kategorie-";

type Node = { id: string; name: string; slug: string; version: number; children: Node[] };
const authorized = (token: string) => ({ Authorization: `Bearer ${token}` });

/** The bearer token of the panel API: opens the screen of the categories on a page that is signed in and reads the first request. */
export async function readToken(page: Page): Promise<string> {
  const pending = waitForBearerToken(page);
  await page.goto(`${urls.admin}/categories`);
  return pending;
}

async function tree(request: APIRequestContext, token: string): Promise<Node[]> {
  const response = await request.get(`${urls.api}/api/v1/admin/categories`, { headers: authorized(token) });
  expect(response.status(), await response.text()).toBe(200);
  return (await response.json()) as Node[];
}

/** Creates a category by the API (the set-up of a test that is not about creating one); returns its id. */
export async function createCategory(request: APIRequestContext, token: string, input: { name: string; slug: string; parentId: string | null }): Promise<string> {
  const response = await request.post(`${urls.api}/api/v1/admin/categories`, { headers: authorized(token), data: input });
  expect(response.status(), await response.text()).toBe(201);
  return ((await response.json()) as { id: string }).id;
}

function ofTests(nodes: Node[]): Node[] {
  return nodes.flatMap((node) => (node.slug.startsWith(SLUG_PREFIX) ? [node] : ofTests(node.children)));
}

/** Deletes every category of the tests, the deepest first (only an empty category can be deleted); nothing else is touched. */
export async function removeTestCategories(request: APIRequestContext, token: string): Promise<void> {
  const remove = async (node: Node): Promise<void> => {
    for (const child of node.children) await remove(child);
    const response = await request.delete(`${urls.api}/api/v1/admin/categories/${node.id}?version=${node.version}`, { headers: authorized(token) });
    expect([204, 404], `deleting ${node.slug}: ${await response.text()}`).toContain(response.status());
  };
  for (const node of ofTests(await tree(request, token))) await remove(node);
}

/** The slugs of all the categories of the tests that are left, for a check that the tree is as it was. */
export async function testCategorySlugs(request: APIRequestContext, token: string): Promise<string[]> {
  const slugs: string[] = [];
  const walk = (nodes: Node[]) => {
    for (const node of nodes) {
      if (node.slug.startsWith(SLUG_PREFIX)) slugs.push(node.slug);
      walk(node.children);
    }
  };
  walk(await tree(request, token));
  return slugs;
}
