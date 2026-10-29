// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadAccountSession } from "@/api/client";
import { AccountMenu } from "./AccountMenu";

vi.mock("@/api/client", () => ({ loadAccountSession: vi.fn() }));
vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams("category=abc&page=2"),
}));

beforeEach(() => {
  vi.mocked(loadAccountSession).mockReset();
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
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: { name: "Jan" } });
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
