// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadAccountSession, loadSiteSwitches } from "@/api/client";
import RootLayout from "./layout";

vi.mock("@/api/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/api/client")>()),
  loadAccountSession: vi.fn(),
  loadSiteSwitches: vi.fn(),
}));
vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn() }),
}));
vi.mock("@/catalog/category-tree", () => ({ loadMenuCategories: async () => null }));

const NOTICE = "Sklep demonstracyjny: płatności i zamówienia są symulowane, nic nie zostanie wysłane ani pobrane.";

const layout = () => RootLayout({ children: <main>content</main> } as unknown as Parameters<typeof RootLayout>[0]);

beforeEach(() => {
  vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
  vi.stubGlobal("fetch", vi.fn(async () => Response.json({ count: 0, itemCount: 0, notice: null })));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
  vi.resetAllMocks();
});

describe("the root layout and the site switches", () => {
  it("shows the demo bar above the header and one read of the switches serves the bar and the account box", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: false });
    render(await layout(), { container: document });

    const bar = await screen.findByText(NOTICE);
    const header = document.querySelector("header")!;
    expect(bar.compareDocumentPosition(header) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    await vi.waitFor(() => expect(loadAccountSession).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
    expect(loadSiteSwitches).toHaveBeenCalledTimes(1);
  });

  it("shows the login link and no bar for the default switches", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
    render(await layout(), { container: document });

    expect(await screen.findByRole("link", { name: "Zaloguj się" })).toBeTruthy();
    expect(screen.queryByText(NOTICE)).toBeNull();
  });

  it("does not read the switches on the server: the HTML is the same whatever the variables are", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: false });
    const without = renderToStaticMarkup(await layout());
    vi.stubEnv("STORE_DEMO_NOTICE", "true");
    vi.stubEnv("STORE_CUSTOMER_ACCOUNTS", "false");
    const withVariables = renderToStaticMarkup(await layout());

    expect(withVariables).toBe(without);
    expect(without).not.toContain("Sklep demonstracyjny");
    expect(loadSiteSwitches).not.toHaveBeenCalled();
  });
});
