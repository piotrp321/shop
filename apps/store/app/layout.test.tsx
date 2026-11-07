import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TREE } from "@/catalog/fixtures";
import Cart from "./cart/page";
import RootLayout from "./layout";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": status === 200 ? "application/json" : "application/problem+json" } });

const render = async (tree: "ok" | "failing") => {
  vi.stubGlobal("fetch", vi.fn(async () => (tree === "ok" ? json(TREE) : json({ status: 503 }, 503))));
  const layout = await RootLayout({ children: <Cart /> } as unknown as Parameters<typeof RootLayout>[0]);
  return renderToStaticMarkup(layout);
};

beforeEach(() => {
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("the root layout", () => {
  it("renders the header with the department menu from the category tree", async () => {
    const html = await render("ok");
    expect(html).toContain('aria-label="Działy"');
    expect(html).toContain('href="/c/bieganie/buty/trailowe"');
    expect(html).toContain('href="/"');
  });

  it("renders the header without the menu, logs on the server and still renders the cart page when the tree fails", async () => {
    const log = vi.spyOn(console, "error").mockImplementation(() => {});
    const html = await render("failing");
    expect(html).not.toContain("Działy");
    expect(html).not.toContain("/c/");
    expect(html).toContain("Rytm");
    expect(html).toContain("Koszyk");
    expect(log).toHaveBeenCalledOnce();
    expect(String(log.mock.calls[0][0])).toContain("categories");
  });

  it("renders the search slot in the header", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json(TREE)));
    const html = renderToStaticMarkup(
      await RootLayout({ children: <p>page</p>, search: <p id="search-slot">slot</p> } as unknown as Parameters<typeof RootLayout>[0]),
    );
    expect(html).toMatch(/<header[^>]*>[\s\S]*<p id="search-slot">slot<\/p>[\s\S]*<\/header>/);
  });

  it("renders the empty search box when there is no slot (the 404 page of an unknown address)", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json(TREE)));
    const html = renderToStaticMarkup(await RootLayout({ children: <p>page</p> } as unknown as Parameters<typeof RootLayout>[0]));
    expect(html).toMatch(/<input[^>]*id="header-search"[^>]*value=""/);
  });

  it("renders no menu for an empty tree", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json([])));
    const html = renderToStaticMarkup(await RootLayout({ children: null } as unknown as Parameters<typeof RootLayout>[0]));
    expect(html).not.toContain("Działy");
  });
});
