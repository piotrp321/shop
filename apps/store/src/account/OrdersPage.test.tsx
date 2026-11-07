// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CustomerOrderList, loadCustomerOrders } from "@/api/client";
import { navigateTo } from "@/checkout/navigate";
import { OrdersPage } from "./OrdersPage";
import { listItem } from "./test-support";

vi.mock("@/api/client", () => ({ loadCustomerOrders: vi.fn() }));
vi.mock("@/checkout/navigate", () => ({ navigateTo: vi.fn() }));

const FAILED = "Nie udało się wczytać zamówień. Spróbuj ponownie.";
const EMPTY = "Nie masz jeszcze zamówień.";
const LOGIN = `/auth/login?returnTo=${encodeURIComponent("/konto/zamowienia")}`;

const FIRST = "0199a0b0-0000-7000-8000-000000000001";
const SECOND = "0199a0b0-0000-7000-8000-000000000002";
const THIRD = "0199a0b0-0000-7000-8000-000000000003";

const page = (items: CustomerOrderList["items"], nextCursor: string | null = null): CustomerOrderList => ({ items, nextCursor });
const ok = (data: CustomerOrderList) => ({ status: "ok" as const, data });

beforeEach(() => {
  vi.mocked(loadCustomerOrders).mockReset();
  vi.mocked(navigateTo).mockReset();
});

afterEach(cleanup);

async function renderPage() {
  render(<OrdersPage />);
  await act(async () => {});
}

const click = (name: string | RegExp) => act(async () => void fireEvent.click(screen.getByRole("button", { name })));
const numbers = () => screen.getAllByRole("link").map((link) => link.textContent);

describe("the order history", () => {
  it("shows what is loading and then every order with its number, date, status, amount and units", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValue(ok(page([listItem({ orderId: FIRST, number: "R-100002", status: "shipped", placedAt: "2026-11-05T23:30:00+00:00", totalGross: 123456, itemCount: 4 })])));
    render(<OrdersPage />);
    expect(screen.getByText("Wczytywanie…")).toBeTruthy();
    await act(async () => {});

    expect(screen.getByRole("heading", { level: 1, name: "Moje zamówienia" })).toBeTruthy();
    const row = screen.getByRole("listitem");
    expect(within(row).getByRole("link", { name: "R-100002" }).getAttribute("href")).toBe(`/konto/zamowienia/${FIRST}`);
    expect(within(row).getByText("6 listopada 2026, 00:30")).toBeTruthy();
    expect(within(row).getByText("Wysłane")).toBeTruthy();
    expect(within(row).getByText(/1234,56/)).toBeTruthy();
    expect(within(row).getByText("4 szt.")).toBeTruthy();
    expect(loadCustomerOrders).toHaveBeenCalledWith();
    expect(screen.queryByRole("button", { name: "Pokaż więcej" })).toBeNull();
  });

  it("says there are no orders yet", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValue(ok(page([])));
    await renderPage();

    expect(screen.getByText(EMPTY)).toBeTruthy();
    expect(screen.queryByRole("list")).toBeNull();
    expect(screen.queryByText(FAILED)).toBeNull();
  });

  it("shows the payment note of an unpaid order from what the API says, in Warsaw time", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValue(
      ok(
        page([
          listItem({ orderId: FIRST, number: "R-100001", status: "new", paymentDeadline: "2026-11-05T12:30:00+00:00", paymentDeadlinePassed: false }),
          listItem({ orderId: SECOND, number: "R-100002", status: "new", paymentDeadlinePassed: true }),
          listItem({ orderId: THIRD, number: "R-100003", status: "paid", paymentDeadlinePassed: false }),
        ]),
      ),
    );
    await renderPage();

    const [waiting, late, paid] = screen.getAllByRole("listitem");
    expect(within(waiting).getByText("Czeka na płatność")).toBeTruthy();
    expect(within(waiting).getByText("Czeka na płatność do 13:30")).toBeTruthy();
    expect(within(late).getByText("Termin płatności minął")).toBeTruthy();
    expect(within(late).queryByText(/Czeka na płatność do/)).toBeNull();
    expect(within(paid).queryByText(/Czeka na płatność do|Termin płatności minął/)).toBeNull();
  });

  it("loads the next page with the cursor and keeps the pages together, without a button on the last page", async () => {
    vi.mocked(loadCustomerOrders)
      .mockResolvedValueOnce(ok(page([listItem({ orderId: FIRST, number: "R-100003" })], "cursor-1")))
      .mockResolvedValueOnce(ok(page([listItem({ orderId: SECOND, number: "R-100002" })], null)));
    await renderPage();
    expect(numbers()).toEqual(["R-100003"]);

    await click("Pokaż więcej");

    expect(loadCustomerOrders).toHaveBeenLastCalledWith("cursor-1");
    expect(numbers()).toEqual(["R-100003", "R-100002"]);
    expect(screen.queryByRole("button", { name: "Pokaż więcej" })).toBeNull();
  });

  it("does not ask for the same page twice while it is loading", async () => {
    let resolve: (value: ReturnType<typeof ok>) => void = () => {};
    vi.mocked(loadCustomerOrders)
      .mockResolvedValueOnce(ok(page([listItem({ orderId: FIRST })], "cursor-1")))
      .mockReturnValueOnce(new Promise((done) => (resolve = done)));
    await renderPage();

    await click("Pokaż więcej");
    const button = screen.getByRole("button", { name: "Pokaż więcej" }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    fireEvent.click(button);
    expect(loadCustomerOrders).toHaveBeenCalledTimes(2);

    await act(async () => resolve(ok(page([listItem({ orderId: SECOND, number: "R-100002" })]))));
    expect(numbers()).toHaveLength(2);
  });

  it("keeps the loaded orders when the next page fails and loads the same page on a retry", async () => {
    vi.mocked(loadCustomerOrders)
      .mockResolvedValueOnce(ok(page([listItem({ orderId: FIRST, number: "R-100003" })], "cursor-1")))
      .mockResolvedValueOnce({ status: "failed" })
      .mockResolvedValueOnce(ok(page([listItem({ orderId: SECOND, number: "R-100002" })], null)));
    await renderPage();

    await click("Pokaż więcej");

    expect(numbers()).toEqual(["R-100003"]);
    expect(screen.getByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(EMPTY)).toBeNull();

    await click("Spróbuj ponownie");

    expect(loadCustomerOrders).toHaveBeenLastCalledWith("cursor-1");
    expect(numbers()).toEqual(["R-100003", "R-100002"]);
    expect(screen.queryByText(FAILED)).toBeNull();
  });

  it("says the orders could not be loaded when the first page fails, and tries again on request", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValueOnce({ status: "failed" }).mockResolvedValueOnce(ok(page([listItem({ orderId: FIRST })])));
    await renderPage();

    expect(screen.getByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(EMPTY)).toBeNull();

    await click("Spróbuj ponownie");

    expect(loadCustomerOrders).toHaveBeenLastCalledWith();
    expect(screen.queryByText(FAILED)).toBeNull();
    expect(numbers()).toHaveLength(1);
  });

  it("sends a visitor without a session to the login and back to the history", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValue({ status: "unauthorized" });
    await renderPage();

    expect(navigateTo).toHaveBeenCalledExactlyOnceWith(LOGIN);
    expect(screen.queryByText(EMPTY)).toBeNull();
    expect(screen.queryByText(FAILED)).toBeNull();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe(LOGIN);
  });

  it("sends a customer whose session ended while the page was open to the login, keeping what was loaded", async () => {
    vi.mocked(loadCustomerOrders)
      .mockResolvedValueOnce(ok(page([listItem({ orderId: FIRST, number: "R-100003" })], "cursor-1")))
      .mockResolvedValueOnce({ status: "unauthorized" });
    await renderPage();

    await click("Pokaż więcej");

    expect(navigateTo).toHaveBeenCalledExactlyOnceWith(LOGIN);
    expect(numbers()).toContain("R-100003");
  });

  it("treats an order not found like a failure of the list", async () => {
    vi.mocked(loadCustomerOrders).mockResolvedValue({ status: "not_found" });
    await renderPage();

    expect(screen.getByText(FAILED)).toBeTruthy();
  });
});
