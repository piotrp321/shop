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

  it("shows the notice of a guest cart that did not fit when the summary carries it", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 99, notice: "merge_truncated" } });
    render(<CartBadge />);

    expect((await screen.findByRole("status")).textContent).toBe("Część produktów z koszyka gościa nie zmieściła się w koszyku");
    expect(count()).toBe("99");
  });

  it("shows no notice when the summary has none", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 2, notice: null } });
    render(<CartBadge />);

    await screen.findByText("2");
    expect(screen.queryByText(/nie zmieściła się/)).toBeNull();
  });

  it("puts the notice in the flow of the header grid as a full-width row, not over the page", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 1, notice: "merge_truncated" } });
    render(<CartBadge />);

    const notice = await screen.findByRole("status");
    expect(notice.className).toContain("col-span-full");
    expect(notice.className).not.toMatch(/\b(absolute|fixed)\b/);
  });

  it("renders its children in the cell of the cart link, so the header grid has them as one item", async () => {
    vi.mocked(loadCartSummary).mockResolvedValue({ status: "ok", data: { itemCount: 1, notice: null } });
    render(
      <CartBadge>
        <a href="/auth/login">Zaloguj się</a>
      </CartBadge>,
    );
    await act(async () => {});

    const cell = screen.getByRole("link", { name: /Koszyk/ }).parentElement!;
    expect(cell.contains(screen.getByRole("link", { name: "Zaloguj się" }))).toBe(true);
  });
});
