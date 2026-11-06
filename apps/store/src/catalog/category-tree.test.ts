import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TREE } from "./fixtures";
import { loadCategoryTree, loadMenuCategories } from "./category-tree";
import { resolveCategoryPath } from "./category-path";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": status === 200 ? "application/json" : "application/problem+json" } });

function stubFetch(handler: () => Response | Promise<Response>) {
  const fetchMock = vi.fn(async (_input: Request | string | URL, _init?: RequestInit) => handler());
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

beforeEach(() => {
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("resolveCategoryPath", () => {
  it("returns the categories from the root down to the one the slugs lead to", () => {
    expect(resolveCategoryPath(TREE, ["bieganie", "buty", "trailowe"]).map((category) => category.id)).toEqual(["bieganie", "buty", "trailowe"]);
    expect(resolveCategoryPath(TREE, ["rower"]).map((category) => category.id)).toEqual(["rower"]);
  });

  it("returns nothing when any slug does not exist at its level", () => {
    expect(resolveCategoryPath(TREE, ["nie-ma"])).toEqual([]);
    expect(resolveCategoryPath(TREE, ["bieganie", "kaski"])).toEqual([]);
    expect(resolveCategoryPath(TREE, ["buty"])).toEqual([]);
    expect(resolveCategoryPath(TREE, ["bieganie", "buty", "trailowe", "dalej"])).toEqual([]);
  });

  it("returns nothing for an empty path and is case sensitive", () => {
    expect(resolveCategoryPath(TREE, [])).toEqual([]);
    expect(resolveCategoryPath(TREE, ["Bieganie"])).toEqual([]);
  });
});

describe("loadCategoryTree", () => {
  it("reads the tree from API_CATALOG_URL with the catalog cache tag", async () => {
    const fetchMock = stubFetch(() => json(TREE));
    expect(await loadCategoryTree()).toEqual(TREE);
    expect(fetchMock).toHaveBeenCalledOnce();
    const [input, init] = fetchMock.mock.calls[0];
    expect(new URL(input instanceof Request ? input.url : String(input)).href).toBe("http://catalog.test/api/v1/categories");
    expect(init).toMatchObject({ next: { tags: ["catalog"] } });
  });

  it("throws on a failing status", async () => {
    stubFetch(() => json({ status: 503 }, 503));
    await expect(loadCategoryTree()).rejects.toThrow(/categories failed with status 503/);
  });

  it("throws on a network error", async () => {
    stubFetch(() => {
      throw new TypeError("fetch failed");
    });
    await expect(loadCategoryTree()).rejects.toThrow("fetch failed");
  });

  it("throws on a body that is not a list", async () => {
    stubFetch(() => json({ unexpected: true }));
    await expect(loadCategoryTree()).rejects.toThrow(/unexpected body/);
  });

  it("fails clearly without the catalog API address, without falling back to the direct one", async () => {
    vi.stubEnv("API_CATALOG_URL", "");
    const fetchMock = stubFetch(() => json(TREE));
    await expect(loadCategoryTree()).rejects.toThrow(/API_CATALOG_URL/);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("loadMenuCategories", () => {
  it("returns the tree", async () => {
    stubFetch(() => json(TREE));
    expect(await loadMenuCategories()).toEqual(TREE);
  });

  it("returns null and logs the failure on the server, so the header can render without the menu", async () => {
    const log = vi.spyOn(console, "error").mockImplementation(() => {});
    stubFetch(() => json({ status: 500 }, 500));
    expect(await loadMenuCategories()).toBeNull();
    expect(log).toHaveBeenCalledOnce();
    expect(String(log.mock.calls[0][0])).toContain("categories");
  });
});
