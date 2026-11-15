import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { categoryRedirectHref, loadCategoryRedirect } from "./category-redirect";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": status === 200 ? "application/json" : "application/problem+json" } });

function stubFetch(handler: () => Response | Promise<Response>) {
  const fetchMock = vi.fn<(input: Request | string | URL, init?: RequestInit) => Promise<Response>>(async () => handler());
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

beforeEach(() => {
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("loadCategoryRedirect", () => {
  it("reads the current path of an old one from API_CATALOG_URL with the catalog cache tag", async () => {
    const fetchMock = stubFetch(() => json({ path: "turystyka/namioty-nowe" }));
    expect(await loadCategoryRedirect("turystyka/namioty")).toBe("turystyka/namioty-nowe");
    expect(fetchMock).toHaveBeenCalledOnce();
    const [input, init] = fetchMock.mock.calls[0];
    expect(new URL(input instanceof Request ? input.url : String(input)).href).toBe(
      "http://catalog.test/api/v1/categories/redirect?path=turystyka%2Fnamioty",
    );
    expect(init).toMatchObject({ next: { tags: ["catalog"] } });
  });

  it("sets no cache mode, like the category tree", async () => {
    const fetchMock = stubFetch(() => json({ path: "a" }));
    await loadCategoryRedirect("b");
    const [input, init] = fetchMock.mock.calls[0];
    expect(init).not.toHaveProperty("cache");
    expect((input as Request).cache).toBe("default");
  });

  it("returns null when the address leads to no category (404)", async () => {
    stubFetch(() => json({ status: 404 }, 404));
    expect(await loadCategoryRedirect("nie-ma")).toBeNull();
  });

  it("returns null for an address the API does not take as a category path (400)", async () => {
    stubFetch(() => json({ status: 400 }, 400));
    expect(await loadCategoryRedirect("Nie Ma/%00")).toBeNull();
  });

  it("throws on any other failing status", async () => {
    stubFetch(() => json({ status: 503 }, 503));
    await expect(loadCategoryRedirect("a")).rejects.toThrow(/categories\/redirect failed with status 503/);
  });

  it("throws on a network error", async () => {
    stubFetch(() => {
      throw new TypeError("fetch failed");
    });
    await expect(loadCategoryRedirect("a")).rejects.toThrow("fetch failed");
  });

  it("throws on a body without a path", async () => {
    stubFetch(() => json({ unexpected: true }));
    await expect(loadCategoryRedirect("a")).rejects.toThrow(/unexpected body/);
    stubFetch(() => json({ path: "" }));
    await expect(loadCategoryRedirect("a")).rejects.toThrow(/unexpected body/);
  });

  it("fails clearly without the catalog API address, without falling back to the direct one", async () => {
    vi.stubEnv("API_CATALOG_URL", "");
    const fetchMock = stubFetch(() => json({ path: "a" }));
    await expect(loadCategoryRedirect("a")).rejects.toThrow(/API_CATALOG_URL/);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("categoryRedirectHref", () => {
  it("is the category address without a query when there are no parameters", () => {
    expect(categoryRedirectHref("a/b", {})).toBe("/c/a/b");
  });

  it("keeps every parameter, also a repeated one, in the order and with the values of the old address", () => {
    expect(categoryRedirectHref("a/b", { sort: "cena-rosnaco", o: ["Kolor:czarne", "Kolor:biały"], page: "2" })).toBe(
      "/c/a/b?sort=cena-rosnaco&o=Kolor%3Aczarne&o=Kolor%3Abia%C5%82y&page=2",
    );
  });

  it("leaves out the listed parameters and the undefined ones", () => {
    expect(categoryRedirectHref("a", { category: "x", page: undefined, sort: "nazwa" }, ["category"])).toBe("/c/a?sort=nazwa");
  });
});
