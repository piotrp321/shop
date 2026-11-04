// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { addCartItem } from "@/api/client";
import { AddToCartButton } from "./AddToCartButton";
import { subscribeToCartChanges } from "./events";

vi.mock("@/api/client", () => ({ addCartItem: vi.fn() }));

const PRODUCT = "00000000-0000-4000-8000-000000000001";
const cartWith = (itemCount: number) => ({ id: "c", lines: [], totals: { gross: 0, currency: "PLN" as const, itemCount }, hasExcludedLines: false });

const counts: number[] = [];
let unsubscribe: () => void;

beforeEach(() => {
  vi.mocked(addCartItem).mockReset();
  counts.length = 0;
  unsubscribe = subscribeToCartChanges((count) => counts.push(count));
});

afterEach(() => {
  unsubscribe();
  cleanup();
});

const button = () => screen.getByRole("button", { name: "Do koszyka" }) as HTMLButtonElement;

describe("AddToCartButton", () => {
  it("adds one unit of its product", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "ok", data: cartWith(1) });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    await screen.findByText("Dodano do koszyka");
    expect(addCartItem).toHaveBeenCalledExactlyOnceWith(PRODUCT, 1);
  });

  it("is disabled while the request runs, so a second click adds nothing", async () => {
    let finish: (value: Awaited<ReturnType<typeof addCartItem>>) => void = () => {};
    vi.mocked(addCartItem).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());
    expect(button().disabled).toBe(true);
    fireEvent.click(button());
    expect(addCartItem).toHaveBeenCalledTimes(1);

    await act(async () => finish({ status: "ok", data: cartWith(1) }));
    expect(button().disabled).toBe(false);
  });

  it("says the product was added and announces the units of the cart from the answer", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "ok", data: cartWith(5) });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    expect((await screen.findByText("Dodano do koszyka")).getAttribute("role")).toBe("status");
    expect(counts).toEqual([5]);
  });

  it("shows the detail of a 409 next to the button and announces nothing", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "conflict", detail: "Nie mamy tylu sztuk tego produktu." });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    expect((await screen.findByText("Nie mamy tylu sztuk tego produktu.")).getAttribute("role")).toBe("alert");
    expect(screen.queryByText("Dodano do koszyka")).toBeNull();
    expect(counts).toEqual([]);
    expect(button().disabled).toBe(false);
  });

  it("falls back to the general message when a 409 has no detail", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "conflict" });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    expect(await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeTruthy();
  });

  it("says the product is no longer available on a 404", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    expect(await screen.findByText("Produkt nie jest już dostępny")).toBeTruthy();
    expect(counts).toEqual([]);
  });

  it.each([["failed" as const], ["unauthorized" as const]])("shows the right message for %s", async (status) => {
    vi.mocked(addCartItem).mockResolvedValue({ status });
    window.history.pushState({}, "", "/?category=buty&page=2");
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());

    if (status === "failed") {
      expect(await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeTruthy();
    } else {
      expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
      expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe(
        `/auth/login?returnTo=${encodeURIComponent("/?category=buty&page=2")}`,
      );
    }
    expect(counts).toEqual([]);
  });

  it("clears the previous message when it is clicked again", async () => {
    vi.mocked(addCartItem).mockResolvedValueOnce({ status: "failed" }).mockResolvedValueOnce({ status: "ok", data: cartWith(1) });
    render(<AddToCartButton productId={PRODUCT} />);

    fireEvent.click(button());
    await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.");
    fireEvent.click(button());

    await screen.findByText("Dodano do koszyka");
    expect(screen.queryByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeNull();
  });
});
