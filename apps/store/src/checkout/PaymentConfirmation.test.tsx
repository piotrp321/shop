// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getPaymentStatus, type PaymentStatusView, startPayment } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { navigateTo } from "./navigate";
import { PaymentConfirmation } from "./PaymentConfirmation";
import type { PaymentProvider } from "./payment-api";

vi.mock("@/api/client", () => ({ getPaymentStatus: vi.fn(), startPayment: vi.fn() }));
vi.mock("./navigate", () => ({ navigateTo: vi.fn() }));

const PROVIDERS: PaymentProvider[] = [
  { code: "demo-transfer", name: "Szybki przelew (demo)" },
  { code: "demo-card", name: "Karta płatnicza (demo)" },
];
const RESUME_URL = "http://localhost:8000/payment-simulator/demo-transfer/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";
const NEW_URL = "http://localhost:8000/payment-simulator/demo-card/pay/0a0a0a0a-4a1b-4c55-9f1e-2b3c4d5e6f70";
const ORDER = { number: "R-100001", email: "anna.nowak@example.com", totalGross: 39998, currency: "PLN" };

const view = (overrides: Partial<PaymentStatusView> = {}): PaymentStatusView => ({
  orderNumber: "R-100001",
  orderStatus: "new",
  // 13:00 UTC is 14:00 in Warsaw in November.
  paymentDeadline: "2026-11-05T13:00:00+00:00",
  totalGross: 39998,
  currency: "PLN",
  canStartPayment: true,
  latestPayment: { provider: "demo-transfer", status: "pending", resumeUrl: RESUME_URL },
  ...overrides,
});

const paid = () => view({ orderStatus: "paid", canStartPayment: false, latestPayment: { provider: "demo-transfer", status: "succeeded", resumeUrl: null } });
const failedPayment = () => view({ latestPayment: { provider: "demo-transfer", status: "failed", resumeUrl: null } });
const afterDeadline = () => view({ canStartPayment: false, latestPayment: { provider: "demo-transfer", status: "pending", resumeUrl: null } });

type Props = Parameters<typeof PaymentConfirmation>[0];
type Initial = Props["initial"];
const ok = (status: PaymentStatusView): Initial => ({ kind: "ok", status });

let keys: number;
const key = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;

beforeEach(() => {
  vi.useFakeTimers();
  vi.mocked(getPaymentStatus).mockReset();
  vi.mocked(startPayment).mockReset();
  vi.mocked(navigateTo).mockReset();
  keys = 0;
  vi.spyOn(crypto, "randomUUID").mockImplementation(() => key(++keys) as `${string}-${string}-${string}-${string}-${string}`);
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

function renderPage(initial: Initial, providers: PaymentProvider[] | null = PROVIDERS) {
  return render(<PaymentConfirmation order={ORDER} initial={initial} providers={providers} />);
}

const advance = (ms: number) => act(async () => void (await vi.advanceTimersByTimeAsync(ms)));
const click = (name: string | RegExp) => act(async () => void fireEvent.click(screen.getByRole("button", { name })));
const choose = (name: string) => fireEvent.click(screen.getByRole("radio", { name }));
const text = (value: string) => screen.queryByText(value);

const WAITING = "Czekamy na potwierdzenie płatności…";
const NO_CONFIRMATION = "Nie mamy jeszcze potwierdzenia płatności.";
const AFTER_DEADLINE =
  "Minął termin płatności. Jeśli zapłaciłeś, czekamy na potwierdzenie; w przeciwnym razie zamówienie zostanie anulowane.";
const START_FAILED = "Nie udało się rozpocząć płatności. Spróbuj ponownie.";
const CHOOSE = "Wybierz sposób płatności.";
const NOT_FOUND = "Nie znaleźliśmy zamówienia.";

describe("the order and its payment", () => {
  it("shows a paid order with the e-mail it was confirmed to, and neither refreshes nor offers a payment", async () => {
    renderPage(ok(paid()));

    expect(screen.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
    expect(screen.getByText("Płatność przyjęta. Potwierdzenie wysłaliśmy na adres anna.nowak@example.com.")).toBeTruthy();
    expect(screen.queryByRole("radiogroup")).toBeNull();
    expect(screen.queryByRole("link", { name: "Wróć do płatności" })).toBeNull();
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");

    await advance(60_000);
    expect(getPaymentStatus).not.toHaveBeenCalled();
  });

  it("shows the number and the total of the API, not of the cookie", async () => {
    renderPage(ok(paid()));
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));

    cleanup();
    render(<PaymentConfirmation order={{ ...ORDER, number: "R-1", totalGross: 1 }} initial={ok(paid())} providers={PROVIDERS} />);
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
  });

  it("waits for a pending payment before the deadline: the deadline in Warsaw time, a way back to the payment, no retry yet", async () => {
    renderPage(ok(view()));

    expect(screen.getByText(WAITING)).toBeTruthy();
    expect(screen.getByText("Opłać do 14:00")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Wróć do płatności" }).getAttribute("href")).toBe(RESUME_URL);
    expect(text(NO_CONFIRMATION)).toBeNull();
    expect(screen.queryByRole("radiogroup")).toBeNull();
    expect(screen.queryByRole("button", { name: "Zapłać innym sposobem" })).toBeNull();
  });

  it("shows the deadline in Warsaw time also in summer", () => {
    renderPage(ok(view({ paymentDeadline: "2026-07-05T13:00:00+00:00" })));

    expect(screen.getByText("Opłać do 15:00")).toBeTruthy();
  });

  it("offers no way back to the payment when the API gave no address", () => {
    renderPage(ok(view({ latestPayment: { provider: "demo-transfer", status: "pending", resumeUrl: null } })));

    expect(screen.getByText(WAITING)).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Wróć do płatności" })).toBeNull();
  });

  it("after the deadline says it waits for a confirmation, without any payment buttons", () => {
    renderPage(ok(afterDeadline()));

    expect(screen.getByText(AFTER_DEADLINE)).toBeTruthy();
    expect(screen.queryByRole("radiogroup")).toBeNull();
    expect(screen.queryByRole("button", { name: /Zapłać/ })).toBeNull();
    expect(screen.queryByRole("link", { name: "Wróć do płatności" })).toBeNull();
    expect(text(WAITING)).toBeNull();
  });

  it("after the deadline says so for any payment, also a failed one or none", () => {
    for (const latestPayment of [null, { provider: "demo-card" as const, status: "failed" as const, resumeUrl: null }]) {
      renderPage(ok(view({ canStartPayment: false, latestPayment })));
      expect(screen.getByText(AFTER_DEADLINE)).toBeTruthy();
      expect(screen.queryByRole("radiogroup")).toBeNull();
      cleanup();
    }
  });

  it.each([
    ["failed", failedPayment(), "Płatność nie powiodła się."],
    ["cancelled", view({ latestPayment: { provider: "demo-transfer", status: "cancelled", resumeUrl: null } }), null],
    ["missing", view({ latestPayment: null }), null],
  ])("offers a new payment before the deadline when the payment is %s", (_name, status, message) => {
    renderPage(ok(status));

    expect(screen.getByText("Opłać do 14:00")).toBeTruthy();
    expect(screen.getByRole("radiogroup", { name: "Sposób płatności" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Zapłać" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zapłać innym sposobem" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Wróć do płatności" })).toBeNull();
    if (message === null) expect(text("Płatność nie powiodła się.")).toBeNull();
    else expect(screen.getByText(message)).toBeTruthy();
    expect(text(WAITING)).toBeNull();
  });

  it("says a cancelled order was cancelled for the missing payment, with a link to the store", async () => {
    renderPage(ok(view({ orderStatus: "cancelled", canStartPayment: false, latestPayment: null })));

    expect(screen.getByText("Zamówienie zostało anulowane, bo nie otrzymaliśmy płatności w terminie.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
    expect(screen.queryByRole("radiogroup")).toBeNull();
    await advance(60_000);
    expect(getPaymentStatus).not.toHaveBeenCalled();
  });

  it("shows the number and the total of the cookie when the status could not be read, and checks again on request", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: paid() });
    renderPage({ kind: "failed" });

    expect(screen.getByText("Nie udało się sprawdzić statusu płatności.")).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
    await click("Sprawdź ponownie");

    expect(getPaymentStatus).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
    expect(text("Nie udało się sprawdzić statusu płatności.")).toBeNull();
  });

  it("keeps the failure when the check fails again", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "failed" });
    renderPage({ kind: "failed" });
    await click("Sprawdź ponownie");

    expect(screen.getByText("Nie udało się sprawdzić statusu płatności.")).toBeTruthy();
  });

  it("says the session expired when a customer's order cannot be read, with a login that comes back here", () => {
    renderPage({ kind: "unauthorized" });

    expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout%2Fconfirmation");
  });

  it("says it did not find the order, with a link to the store", () => {
    renderPage({ kind: "not_found" });

    expect(screen.getByText(NOT_FOUND)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });
});

describe("refreshing the status", () => {
  it("asks every 2 seconds for 30 seconds while a payment is pending, then stops and offers a retry", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    renderPage(ok(view()));

    await advance(1_999);
    expect(getPaymentStatus).not.toHaveBeenCalled();
    await advance(1);
    expect(getPaymentStatus).toHaveBeenCalledTimes(1);
    await advance(28_000);
    const calls = vi.mocked(getPaymentStatus).mock.calls.length;
    expect(calls).toBeGreaterThanOrEqual(14);
    expect(calls).toBeLessThanOrEqual(15);

    await advance(30_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(calls);
    expect(screen.getByText(NO_CONFIRMATION)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Wróć do płatności" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Sprawdź ponownie" })).toBeTruthy();
    expect(screen.getByRole("radiogroup", { name: "Sposób płatności" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Zapłać innym sposobem" })).toBeTruthy();
    // The waiting text stays: the extra offer is in addition to it.
    expect(screen.getByText(WAITING)).toBeTruthy();
  });

  it("shows the paid order as soon as the API says so, and stops asking", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValueOnce({ status: "ok", data: view() }).mockResolvedValue({ status: "ok", data: paid() });
    renderPage(ok(view()));

    await advance(4_000);
    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
    const calls = vi.mocked(getPaymentStatus).mock.calls.length;
    await advance(40_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(calls);
  });

  it("follows the order to the state after the deadline and goes on asking for the 30 seconds, then offers a check", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: afterDeadline() });
    renderPage(ok(view()));

    await advance(2_000);
    expect(screen.getByText(AFTER_DEADLINE)).toBeTruthy();
    await advance(10_000);
    expect(vi.mocked(getPaymentStatus).mock.calls.length).toBeGreaterThanOrEqual(5);

    await advance(30_000);
    const calls = vi.mocked(getPaymentStatus).mock.calls.length;
    await advance(10_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(calls);
    expect(screen.getByRole("button", { name: "Sprawdź ponownie" })).toBeTruthy();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("refreshes an order after the deadline from the start too", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: afterDeadline() });
    renderPage(ok(afterDeadline()));

    await advance(2_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(1);
  });

  it("does not refresh when there is nothing to wait for (no payment, a failed one)", async () => {
    renderPage(ok(failedPayment()));
    await advance(60_000);

    expect(getPaymentStatus).not.toHaveBeenCalled();
  });

  it("keeps the last status when a refresh fails, and goes on", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValueOnce({ status: "failed" }).mockResolvedValue({ status: "ok", data: paid() });
    renderPage(ok(view()));

    await advance(2_000);
    expect(screen.getByText(WAITING)).toBeTruthy();
    expect(text("Nie udało się sprawdzić statusu płatności.")).toBeNull();
    await advance(2_000);
    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
  });

  it.each([
    ["unauthorized", { status: "unauthorized" } as const, /Twoja sesja wygasła/],
    ["not found", { status: "not_found" } as const, new RegExp(NOT_FOUND)],
  ])("replaces the page when a refresh says %s", async (_name, result, expected) => {
    vi.mocked(getPaymentStatus).mockResolvedValue(result);
    renderPage(ok(view()));
    await advance(2_000);

    expect(screen.getByText(expected)).toBeTruthy();
    const calls = vi.mocked(getPaymentStatus).mock.calls.length;
    await advance(10_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(calls);
  });

  it("checks once on request after the 30 seconds, without refreshing again by itself", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    renderPage(ok(view()));
    await advance(31_000);
    const before = vi.mocked(getPaymentStatus).mock.calls.length;

    await click("Sprawdź ponownie");
    expect(getPaymentStatus).toHaveBeenCalledTimes(before + 1);
    await advance(10_000);
    expect(getPaymentStatus).toHaveBeenCalledTimes(before + 1);
  });

  it("does not refresh after the page is left", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    const { unmount } = renderPage(ok(view()));
    unmount();
    await advance(10_000);

    expect(getPaymentStatus).not.toHaveBeenCalled();
  });
});

describe("paying", () => {
  const showRetry = () => renderPage(ok(failedPayment()));

  it("asks for a choice without asking the API when no provider is chosen, and moves the focus to the group", async () => {
    showRetry();
    await click("Zapłać");

    expect(startPayment).not.toHaveBeenCalled();
    const group = screen.getByRole("radiogroup", { name: "Sposób płatności" });
    expect(screen.getByText(CHOOSE).id).toBe(group.getAttribute("aria-describedby"));
    expect(document.activeElement).toBe(screen.getAllByRole("radio")[0]);
  });

  it("goes to the page of the provider and keeps the button locked", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "ok", redirectUrl: NEW_URL });
    showRetry();
    choose("Karta płatnicza (demo)");
    await click("Zapłać");

    expect(startPayment).toHaveBeenCalledWith("demo-card", key(1));
    expect(navigateTo).toHaveBeenCalledWith(NEW_URL);
    expect((screen.getByRole("button", { name: "Zapłać" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("starts the new payment with the label „Zapłać innym sposobem” when the old one is still pending", async () => {
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    vi.mocked(startPayment).mockResolvedValue({ status: "ok", redirectUrl: NEW_URL });
    renderPage(ok(view()));
    await advance(31_000);
    choose("Karta płatnicza (demo)");
    await click("Zapłać innym sposobem");

    expect(startPayment).toHaveBeenCalledWith("demo-card", expect.any(String));
    expect(navigateTo).toHaveBeenCalledWith(NEW_URL);
  });

  it("locks the button while the request runs, so a double click makes one request", async () => {
    let answer: (result: Awaited<ReturnType<typeof startPayment>>) => void = () => {};
    vi.mocked(startPayment).mockReturnValue(new Promise((resolve) => (answer = resolve)));
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");
    fireEvent.click(screen.getByRole("button", { name: "Zapłać" }));

    expect(startPayment).toHaveBeenCalledTimes(1);
    await act(async () => answer({ status: "failed" }));
    expect((screen.getByRole("button", { name: "Zapłać" }) as HTMLButtonElement).disabled).toBe(false);
  });

  it("uses a new key for every attempt that ended for good", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "in_progress", detail: "Płatność za to zamówienie jest w toku." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: failedPayment() });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");
    await click("Zapłać");

    expect(vi.mocked(startPayment).mock.calls.map((call) => call[1])).toEqual([key(1), key(2)]);
  });

  it("repeats an uncertain result with the same key and the same provider, and a new key after a change of provider", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "failed" });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");
    await click("Zapłać");
    choose("Karta płatnicza (demo)");
    await click("Zapłać");
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(vi.mocked(startPayment).mock.calls).toEqual([
      ["demo-transfer", key(1)],
      ["demo-transfer", key(1)],
      ["demo-card", key(2)],
      ["demo-transfer", key(3)],
    ]);
  });

  it("answers an uncertain result with a message and keeps the page", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "failed" });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(START_FAILED);
    expect(alert.getAttribute("tabindex")).toBe("-1");
    expect(document.activeElement).toBe(alert);
  });

  it("on 409 payment in progress shows the detail, reads the status again and goes on refreshing", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "in_progress", detail: "Płatność została już wykonana. Czekamy na jej potwierdzenie." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    renderPage(ok(view()));
    await advance(31_000);
    const before = vi.mocked(getPaymentStatus).mock.calls.length;
    choose("Szybki przelew (demo)");
    await click("Zapłać innym sposobem");

    expect(screen.getByRole("alert").textContent).toContain("Płatność została już wykonana. Czekamy na jej potwierdzenie.");
    expect(getPaymentStatus).toHaveBeenCalledTimes(before + 1);
    expect(screen.getByText(WAITING)).toBeTruthy();
    await advance(6_000);
    expect(vi.mocked(getPaymentStatus).mock.calls.length).toBeGreaterThan(before + 1);
  });

  it("on 409 payment in progress from a page without a payment waits for the one in progress", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "in_progress", detail: "Płatność za to zamówienie jest w toku. Spróbuj za chwilę." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view() });
    renderPage(ok(view({ latestPayment: null })));
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText(WAITING)).toBeTruthy();
    expect(screen.getByText("Płatność za to zamówienie jest w toku. Spróbuj za chwilę.")).toBeTruthy();
  });

  it("on 409 order already paid shows the paid order", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "already_paid", detail: "Zamówienie jest już opłacone." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: paid() });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
    expect(screen.queryByText("Zamówienie jest już opłacone.")).toBeNull();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("on 409 deadline passed shows the state after the deadline", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "deadline_passed", detail: "Minął termin płatności za to zamówienie." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: afterDeadline() });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText(AFTER_DEADLINE)).toBeTruthy();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("on 409 deadline passed shows the cancelled order when the API says so", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "deadline_passed", detail: "Minął termin płatności za to zamówienie." });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: view({ orderStatus: "cancelled", canStartPayment: false, latestPayment: null }) });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText("Zamówienie zostało anulowane, bo nie otrzymaliśmy płatności w terminie.")).toBeTruthy();
  });

  it("on 409 reused key shows the detail and tries again with a new key", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "key_reused", detail: "Ten klucz żądania był już użyty dla innej płatności." });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByRole("alert").textContent).toContain("Ten klucz żądania był już użyty dla innej płatności.");
    await click("Zapłać");
    expect(vi.mocked(startPayment).mock.calls.map((call) => call[1])).toEqual([key(1), key(2)]);
  });

  it("on an unknown 409 asks to try again with the same key", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "conflict", reason: "unknown" });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");
    await click("Zapłać");

    expect(screen.getByRole("alert").textContent).toContain(START_FAILED);
    expect(vi.mocked(startPayment).mock.calls.map((call) => call[1])).toEqual([key(1), key(1)]);
  });

  it("on 404 says it did not find the order, with a link to the store", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "not_found", detail: NOT_FOUND });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText(NOT_FOUND)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("on 404 moves the focus to the message, because the button was locked", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "not_found", detail: NOT_FOUND });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(NOT_FOUND);
    expect(alert.getAttribute("tabindex")).toBe("-1");
    expect(document.activeElement).toBe(alert);
  });

  it("on 401 moves the focus to the message with the login link", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "unauthorized" });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Twoja sesja wygasła. Zaloguj się ponownie.");
    expect(alert.getAttribute("tabindex")).toBe("-1");
    expect(document.activeElement).toBe(alert);
  });

  it("when the API had no address to give moves the focus to the heading of the refreshed page", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "ok", redirectUrl: null });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: paid() });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    const heading = screen.getByRole("heading", { level: 1 });
    expect(heading.getAttribute("tabindex")).toBe("-1");
    expect(document.activeElement).toBe(heading);
  });

  it("on 422 shows the message at the group", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "invalid", violations: [{ propertyPath: "provider", title: "Nieznany sposób płatności." }] });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    const group = screen.getByRole("radiogroup", { name: "Sposób płatności" });
    expect(screen.getByText("Nieznany sposób płatności.").id).toBe(group.getAttribute("aria-describedby"));
    expect(document.activeElement).toBe(screen.getAllByRole("radio")[0]);
  });

  it("on 422 without a field shows the detail as a message", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "invalid", detail: "Nieprawidłowe dane.", violations: [] });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByRole("alert").textContent).toContain("Nieprawidłowe dane.");
  });

  it("on 401 says the session expired, with a login that comes back here", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "unauthorized" });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout%2Fconfirmation");
  });

  it("reads the status again when the API had no address to give", async () => {
    vi.mocked(startPayment).mockResolvedValue({ status: "ok", redirectUrl: null });
    vi.mocked(getPaymentStatus).mockResolvedValue({ status: "ok", data: paid() });
    showRetry();
    choose("Szybki przelew (demo)");
    await click("Zapłać");

    expect(navigateTo).not.toHaveBeenCalled();
    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
  });

  it.each([
    ["could not be read", null],
    ["is empty", []],
  ])("says payments are unavailable instead of the button when the list of providers %s", (_name, providers) => {
    renderPage(ok(failedPayment()), providers);

    expect(screen.getByText("Płatności są chwilowo niedostępne. Spróbuj za chwilę.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zapłać" })).toBeNull();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("lists the providers of the API without a default", () => {
    showRetry();

    const radios = within(screen.getByRole("radiogroup", { name: "Sposób płatności" })).getAllByRole("radio") as HTMLInputElement[];
    expect(radios.map((radio) => radio.labels?.[0]?.textContent)).toEqual(["Szybki przelew (demo)", "Karta płatnicza (demo)"]);
    expect(radios.some((radio) => radio.checked)).toBe(false);
  });
});
