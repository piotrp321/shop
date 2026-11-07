// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadCustomerOrder } from "@/api/client";
import { navigateTo } from "@/checkout/navigate";
import { OrderDetailPage } from "./OrderDetailPage";
import { detail, ORDER_ID } from "./test-support";

vi.mock("@/api/client", () => ({ loadCustomerOrder: vi.fn() }));
vi.mock("@/checkout/navigate", () => ({ navigateTo: vi.fn() }));

const FAILED = "Nie udało się wczytać zamówień. Spróbuj ponownie.";
const NOT_FOUND = "Nie znaleźliśmy zamówienia.";
const LOGIN = `/auth/login?returnTo=${encodeURIComponent(`/konto/zamowienia/${ORDER_ID}`)}`;

const ok = (data: ReturnType<typeof detail>) => ({ status: "ok" as const, data });

beforeEach(() => {
  vi.mocked(loadCustomerOrder).mockReset();
  vi.mocked(navigateTo).mockReset();
});

afterEach(cleanup);

async function renderPage() {
  render(<OrderDetailPage orderId={ORDER_ID} />);
  await act(async () => {});
}

describe("the order detail", () => {
  it("shows the number, status, lines, totals from the API and the address", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail()));
    render(<OrderDetailPage orderId={ORDER_ID} />);
    expect(screen.getByText("Wczytywanie…")).toBeTruthy();
    await act(async () => {});

    expect(loadCustomerOrder).toHaveBeenCalledWith(ORDER_ID);
    expect(screen.getByRole("heading", { level: 1, name: "Zamówienie R-100001" })).toBeTruthy();
    expect(screen.getByTestId("order-status").textContent).toBe("Opłacone");

    const lines = within(screen.getByRole("list", { name: "Pozycje zamówienia" })).getAllByRole("listitem");
    expect(lines).toHaveLength(2);
    expect(within(lines[0]).getByText("Buty szosowe Tarvik Asfalt 200")).toBeTruthy();
    expect(within(lines[0]).getByText("czarne, 42")).toBeTruthy();
    expect(within(lines[0]).getByText(/2 ×/)).toBeTruthy();
    expect(within(lines[0]).getByText(/199,99/, { selector: "[data-testid=unit-price]" })).toBeTruthy();
    expect(within(lines[0]).getByTestId("line-total").textContent).toMatch(/399,98/);
    expect(within(lines[1]).getByText("Czapka")).toBeTruthy();
    expect(within(lines[1]).queryByText("czarne, 42")).toBeNull();

    // The sums are the API's, not the page's.
    expect(screen.getByTestId("total-net").textContent).toMatch(/341,45/);
    expect(screen.getByTestId("total-vat").textContent).toMatch(/78,53/);
    expect(screen.getByTestId("total-gross").textContent).toMatch(/419,98/);

    const address = screen.getByRole("group", { name: "Adres dostawy" });
    expect(address.textContent).toContain("ul. Długa 5/3");
    expect(address.textContent).toContain("00-950 Warszawa");
  });

  it("lists the dates of the statuses the order went through, in Warsaw time", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(
      ok(detail({ status: "delivered", shippedAt: "2026-11-06T09:00:00+00:00", deliveredAt: "2026-11-07T23:30:00+00:00" })),
    );
    await renderPage();

    const dates = screen.getByRole("group", { name: "Daty zamówienia" });
    expect(dates.textContent).toBe(
      ["Złożone", "5 listopada 2026, 13:00", "Opłacone", "5 listopada 2026, 13:10", "Wysłane", "6 listopada 2026, 10:00", "Dostarczone", "8 listopada 2026, 00:30"].join(""),
    );
  });

  it.each([
    ["cancelled", { cancelledAt: "2026-11-06T09:00:00+00:00" }, "Anulowane", "6 listopada 2026, 10:00"],
    ["returned", { shippedAt: "2026-11-06T09:00:00+00:00", returnedAt: "2026-11-09T09:00:00+00:00" }, "Zwrócone", "9 listopada 2026, 10:00"],
  ] as const)("shows the date of a %s order", async (status, moments, label, text) => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail({ status, ...moments })));
    await renderPage();

    expect(screen.getByTestId("order-status").textContent).toBe(label);
    const dates = within(screen.getByRole("group", { name: "Daty zamówienia" }));
    expect(dates.getByText(text)).toBeTruthy();
  });

  it("leaves out the dates the order has not reached", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail({ status: "new", paidAt: null })));
    await renderPage();

    const dates = screen.getByRole("group", { name: "Daty zamówienia" });
    expect(dates.textContent).toBe("Złożone5 listopada 2026, 13:00");
  });

  it("tells until when an unpaid order can be paid, and that the term passed, as the API says", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail({ status: "new", paidAt: null, paymentDeadline: "2026-11-05T12:30:00+00:00", paymentDeadlinePassed: false })));
    await renderPage();

    expect(screen.getByText("Czeka na płatność do 13:30")).toBeTruthy();
    expect(screen.queryByText("Termin płatności minął")).toBeNull();
    expect(screen.queryByRole("button")).toBeNull();
    cleanup();

    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail({ status: "new", paidAt: null, paymentDeadlinePassed: true })));
    await renderPage();

    expect(screen.getByText("Termin płatności minął")).toBeTruthy();
    expect(screen.queryByText(/Czeka na płatność do/)).toBeNull();
  });

  it("says nothing of the payment term for an order that is not new", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail({ status: "paid" })));
    await renderPage();

    expect(screen.queryByText(/Czeka na płatność do|Termin płatności minął/)).toBeNull();
  });

  it("has a link back to the history", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue(ok(detail()));
    await renderPage();

    expect(screen.getByRole("link", { name: "Wróć do zamówień" }).getAttribute("href")).toBe("/konto/zamowienia");
  });

  it("says the order was not found, with a link back to the history", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue({ status: "not_found" });
    await renderPage();

    expect(screen.getByText(NOT_FOUND)).toBeTruthy();
    expect(screen.queryByText(FAILED)).toBeNull();
    expect(screen.getByRole("link", { name: "Wróć do zamówień" }).getAttribute("href")).toBe("/konto/zamowienia");
  });

  it("says the order could not be loaded and tries again on request", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValueOnce({ status: "failed" }).mockResolvedValueOnce(ok(detail()));
    await renderPage();

    expect(screen.getByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(NOT_FOUND)).toBeNull();

    await act(async () => void fireEvent.click(screen.getByRole("button", { name: "Spróbuj ponownie" })));

    expect(loadCustomerOrder).toHaveBeenCalledTimes(2);
    expect(screen.queryByText(FAILED)).toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "Zamówienie R-100001" })).toBeTruthy();
  });

  it("sends a visitor without a session to the login and back to this order", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue({ status: "unauthorized" });
    await renderPage();

    expect(navigateTo).toHaveBeenCalledExactlyOnceWith(LOGIN);
    expect(screen.queryByText(NOT_FOUND)).toBeNull();
    expect(screen.queryByText(FAILED)).toBeNull();
  });

  it("encodes an id of an odd shape in the path to come back to", async () => {
    vi.mocked(loadCustomerOrder).mockResolvedValue({ status: "unauthorized" });
    render(<OrderDetailPage orderId="a b/c" />);
    await act(async () => {});

    expect(navigateTo).toHaveBeenCalledWith(`/auth/login?returnTo=${encodeURIComponent("/konto/zamowienia/a%20b%2Fc")}`);
  });
});
