// @vitest-environment jsdom
import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadCartSummary } from "@/api/client";
import { CartBadge } from "./CartBadge";
import { announceCartChange } from "./events";

vi.mock("@/api/client", () => ({ loadCartSummary: vi.fn() }));

beforeEach(() => {
  vi.mocked(loadCartSummary).mockReset();
});
afterEach(cleanup);

const count = () => screen.queryByTestId("cart-count")?.textContent ?? null;

describe("CartBadge", () => {
  it("links to the cart and shows the units from the summary", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 3, notice: null } });
    render(<CartBadge />);

    expect(screen.getByRole("link", { name: /Koszyk/ }).getAttribute("href")).toBe("/cart");
    await screen.findByText("3");
    expect(count()).toBe("3");
  });

  it("shows no number while the summary is loading", () => {
    vi.mocked(loadCartSummary).mockReturnValue(new Promise(() => {}));
    render(<CartBadge />);

    expect(screen.getByRole("link", { name: "Koszyk" })).toBeTruthy();
    expect(count()).toBeNull();
  });

  it("shows 0 for an empty cart", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 0, notice: null } });
    render(<CartBadge />);

    await screen.findByTestId("cart-count");
    expect(count()).toBe("0");
  });

  it("updates to the units announced after a change of the cart", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 1, notice: null } });
    render(<CartBadge />);
    await screen.findByText("1");

    act(() => announceCartChange(4));

    expect(count()).toBe("4");
  });

  it("keeps a number announced while the summary was loading", async () => {
    let finish: (value: Awaited<ReturnType<typeof loadCartSummary>>) => void = () => {};
    vi.mocked(loadCartSummary).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    render(<CartBadge />);

    act(() => announceCartChange(2));
    await act(async () => finish({ status: "ok", data: { itemCount: 0, notice: null } }));

    expect(count()).toBe("2");
  });

  it.each([["failed" as const], ["unauthorized" as const]])("hides the number but keeps the link when the summary is %s", async (status) => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status });
    render(<CartBadge />);

    await act(async () => {});
    expect(count()).toBeNull();
    expect(screen.getByRole("link", { name: "Koszyk" }).getAttribute("href")).toBe("/cart");
  });

  it("shows a number announced after a failed summary", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "failed" });
    render(<CartBadge />);
    await act(async () => {});

    act(() => announceCartChange(1));

    expect(count()).toBe("1");
  });
});
