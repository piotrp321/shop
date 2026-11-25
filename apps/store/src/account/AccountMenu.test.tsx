// @vitest-environment jsdom
import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type AccountSessionResult, loadAccountSession, loadSiteSwitches } from "@/api/client";
import { SiteSwitchesProvider } from "@/components/SiteSwitches";
import { AccountMenu } from "./AccountMenu";
import { announceSession } from "./session-events";

vi.mock("@/api/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/api/client")>()),
  loadAccountSession: vi.fn(),
  loadSiteSwitches: vi.fn(),
}));
vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams("category=abc&page=2"),
}));

beforeEach(() => {
  vi.mocked(loadAccountSession).mockReset();
  vi.mocked(loadSiteSwitches).mockReset();
});

afterEach(cleanup);

describe("AccountMenu", () => {
  it("links to the login with the current path as returnTo when nobody is signed in", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<AccountMenu />);

    const link = await screen.findByRole("link", { name: "Zaloguj się" });
    expect(link.getAttribute("href")).toBe(`/auth/login?returnTo=${encodeURIComponent("/?category=abc&page=2")}`);
    expect(screen.queryByText(/Zalogowany jako/)).toBeNull();
  });

  it("shows the name and a logout form that POSTs to /auth/logout", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: { name: "Jan", email: "jan.kowalski@example.com", buyer: "buyer-tag-of-jan" } });
    render(<AccountMenu />);

    expect(await screen.findByText("Zalogowany jako Jan")).toBeTruthy();
    const button = screen.getByRole("button", { name: "Wyloguj się" });
    expect(button.getAttribute("data-slot")).toBe("button");
    expect(button.getAttribute("type")).toBe("submit");
    const form = button.closest("form")!;
    expect(form.getAttribute("method")).toBe("post");
    expect(form.getAttribute("action")).toBe("/auth/logout");
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("shows neither while the session is loading", () => {
    vi.mocked(loadAccountSession).mockReturnValue(new Promise(() => {}));
    render(<AccountMenu />);

    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("shows neither when the session cannot be checked", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "unavailable" });
    render(<AccountMenu />);

    await vi.waitFor(() => expect(loadAccountSession).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.queryByRole("button")).toBeNull();
  });
});

// The checkout reads the session again after a changed buyer (docs/features/checkout/spec.md, „Zmiana kupującego”):
// the header shows what it read, without a request of its own (U3).
describe("AccountMenu after another part of the page read the session", () => {
  const JAN = { name: "Jan Kowalski", email: "jan.kowalski@example.com", buyer: "buyer-tag-of-jan" };
  const ANNA = { name: "Anna Nowak", email: "anna.nowak@example.com", buyer: "buyer-tag-of-anna" };
  const renderOnSite = () =>
    render(
      <SiteSwitchesProvider>
        <AccountMenu />
      </SiteSwitchesProvider>,
    );

  beforeEach(() => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
  });

  it("shows the account announced, then nobody, with the login link, and reads nothing itself", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: JAN });
    renderOnSite();
    expect(await screen.findByText("Zalogowany jako Jan Kowalski")).toBeTruthy();

    act(() => announceSession(ANNA));
    expect(screen.getByText("Zalogowany jako Anna Nowak")).toBeTruthy();
    expect(screen.queryByText(/Jan Kowalski/)).toBeNull();

    act(() => announceSession(null));
    expect(screen.getByRole("link", { name: "Zaloguj się" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Wyloguj się" })).toBeNull();
    expect(loadAccountSession).toHaveBeenCalledTimes(1);
  });

  it("shows the account announced to a guest", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    renderOnSite();
    await screen.findByRole("link", { name: "Zaloguj się" });

    act(() => announceSession(JAN));
    expect(screen.getByText("Zalogowany jako Jan Kowalski")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("keeps the session announced while its own read was on its way, when that read answers later", async () => {
    let answer: (result: AccountSessionResult) => void = () => {};
    vi.mocked(loadAccountSession).mockReturnValue(new Promise((resolve) => (answer = resolve)));
    renderOnSite();

    act(() => announceSession(ANNA));
    await act(async () => answer({ status: "ok", session: JAN }));

    expect(screen.getByText("Zalogowany jako Anna Nowak")).toBeTruthy();
    expect(screen.queryByText(/Jan Kowalski/)).toBeNull();
  });
});

describe("AccountMenu with the site switches", () => {
  const renderOnSite = () =>
    render(
      <SiteSwitchesProvider>
        <AccountMenu />
      </SiteSwitchesProvider>,
    );

  it("hides the login link when the customer accounts are off", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: false });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    renderOnSite();

    await vi.waitFor(() => expect(loadSiteSwitches).toHaveBeenCalled());
    await vi.waitFor(() => expect(loadAccountSession).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("shows the login link when the customer accounts are on", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    renderOnSite();

    expect(await screen.findByRole("link", { name: "Zaloguj się" })).toBeTruthy();
  });

  it("does not show the login link before the switches are read, so it never flashes on a store without accounts", async () => {
    vi.mocked(loadSiteSwitches).mockReturnValue(new Promise(() => {}));
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    renderOnSite();

    await vi.waitFor(() => expect(loadAccountSession).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("shows the login link when the switches cannot be read (the default state)", async () => {
    // loadSiteSwitches itself gives the defaults for a failed read.
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    renderOnSite();

    expect(await screen.findByRole("link", { name: "Zaloguj się" })).toBeTruthy();
  });

  it("still shows a signed-in customer and the logout form when the customer accounts are off", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: false });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: { name: "Jan", email: "jan.kowalski@example.com", buyer: "buyer-tag-of-jan" } });
    renderOnSite();

    expect(await screen.findByText("Zalogowany jako Jan")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Wyloguj się" })).toBeTruthy();
  });
});

describe("AccountMenu: the order history", () => {
  it("links a signed-in customer to their orders", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: { name: "Jan", email: "jan.kowalski@example.com", buyer: "buyer-tag-of-jan" } });
    render(<AccountMenu />);

    const link = await screen.findByRole("link", { name: "Moje zamówienia" });
    expect(link.getAttribute("href")).toBe("/konto/zamowienia");
  });

  it("does not offer the orders to a visitor, while loading or when the session cannot be checked", async () => {
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<AccountMenu />);
    await screen.findByRole("link", { name: "Zaloguj się" });
    expect(screen.queryByRole("link", { name: "Moje zamówienia" })).toBeNull();
    cleanup();

    vi.mocked(loadAccountSession).mockReturnValue(new Promise(() => {}));
    render(<AccountMenu />);
    expect(screen.queryByRole("link", { name: "Moje zamówienia" })).toBeNull();
    cleanup();

    vi.mocked(loadAccountSession).mockResolvedValue({ status: "unavailable" });
    render(<AccountMenu />);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.queryByRole("link", { name: "Moje zamówienia" })).toBeNull();
  });
});
