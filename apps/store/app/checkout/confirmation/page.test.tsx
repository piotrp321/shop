// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { cookies } from "next/headers";
import { isValidElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { readAuthConfig } from "@/auth/config";
import { sessionData, testConfig } from "@/auth/test-support";
import { getCartDeps } from "@/cart/deps";
import {
  cartTestDeps,
  customerConfirmation,
  guestConfirmation,
  ORDER_ACCESS_TOKEN,
  ORDER_ID,
  problem,
  sessionCookie,
  signedConfirmationValue,
} from "@/cart/test-support";
import { formatPrice } from "@/catalog/format-price";
import { PaymentConfirmation } from "@/checkout/PaymentConfirmation";
import { signValue } from "@/auth/cookies";
import OrderConfirmationPage, { metadata } from "./page";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));
vi.mock("@/auth/config", () => ({ readAuthConfig: vi.fn() }));
vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

const COOKIE = "store_order_confirmation";
const GUEST_STATUS = `/api/v1/orders/${ORDER_ID}/payment`;
const CUSTOMER_STATUS = `/api/v1/me/orders/${ORDER_ID}/payment`;
const PROVIDERS = "/api/v1/payment-providers";
const encoded = (value: unknown) => Buffer.from(JSON.stringify(value)).toString("base64url");

let setup: ReturnType<typeof cartTestDeps>;
// Handlers answer in the order they were added, so a test changes the answer of the default one.
let guestStatus: () => Response;

/** Cookies of the request by name; the value is what the browser sends. */
const withCookies = (values: Record<string, string | undefined>) =>
  vi.mocked(cookies).mockResolvedValue({
    get: (name: string) => (values[name] !== undefined ? { name, value: values[name] } : undefined),
  } as unknown as Awaited<ReturnType<typeof cookies>>);

const withConfirmation = (value: string | undefined) => withCookies({ [COOKIE]: value });

const statusView = (overrides: Partial<components["schemas"]["PaymentStatusView"]> = {}): components["schemas"]["PaymentStatusView"] => ({
  orderNumber: "R-100001",
  orderStatus: "new",
  paymentDeadline: "2026-11-05T13:00:00+00:00",
  totalGross: 39998,
  currency: "PLN",
  canStartPayment: true,
  latestPayment: { provider: "demo-transfer", status: "pending", resumeUrl: "http://localhost:8000/payment-simulator/demo-transfer/pay/abc" },
  ...overrides,
});
const paid = () => statusView({ orderStatus: "paid", canStartPayment: false, latestPayment: { provider: "demo-transfer", status: "succeeded", resumeUrl: null } });

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.mocked(readAuthConfig).mockReturnValue(testConfig);
  setup.answer("GET", PROVIDERS, () =>
    Response.json([
      { code: "demo-transfer", name: "Szybki przelew (demo)" },
      { code: "demo-card", name: "Karta płatnicza (demo)" },
    ]),
  );
  guestStatus = () => Response.json(statusView());
  setup.answer("GET", GUEST_STATUS, () => guestStatus());
  vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(cleanup);

const renderPage = async () => render(await OrderConfirmationPage());

const expectNotFound = () => {
  expect(screen.getByText("Nie znaleźliśmy informacji o zamówieniu.")).toBeTruthy();
  expect(screen.queryByText("Dziękujemy za zamówienie")).toBeNull();
  expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
  expect(setup.calls).toEqual([]);
};

describe("the order confirmation page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("shows a paid order of a guest, read with its access token", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    guestStatus = () => Response.json(paid());
    await renderPage();

    expect(screen.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
    expect(screen.getByText("Płatność przyjęta. Potwierdzenie wysłaliśmy na adres anna.nowak@example.com.")).toBeTruthy();
    expect(setup.calls.find((call) => call.path === GUEST_STATUS)).toMatchObject({ orderAccessToken: ORDER_ACCESS_TOKEN, authorization: null });
  });

  it("gives the page of the browser the providers and the status, but never the access token", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    const element = await OrderConfirmationPage();

    expect(isValidElement(element) && element.type).toBe(PaymentConfirmation);
    const props = (element as { props: Parameters<typeof PaymentConfirmation>[0] }).props;
    expect(props.order).toEqual({ number: "R-100001", email: "anna.nowak@example.com", totalGross: 39998, currency: "PLN" });
    expect(props.initial).toEqual({ kind: "ok", status: statusView() });
    expect(props.providers).toHaveLength(2);
    expect(JSON.stringify(props)).not.toContain(ORDER_ACCESS_TOKEN);
    expect(JSON.stringify(props)).not.toContain(ORDER_ID);
  });

  it("reads the status of a customer's order with the token of the session", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    withCookies({ [COOKIE]: signedConfirmationValue(customerConfirmation), store_session: sessionCookie(id).split("=")[1] });
    setup.answer("GET", CUSTOMER_STATUS, () => Response.json(paid()));
    await renderPage();

    expect(screen.getByText(/^Płatność przyjęta/)).toBeTruthy();
    expect(setup.calls.find((call) => call.path === CUSTOMER_STATUS)).toMatchObject({ authorization: "Bearer access-9" });
  });

  it("asks a customer's order without a session to log in again", async () => {
    withConfirmation(signedConfirmationValue(customerConfirmation));
    await renderPage();

    expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout%2Fconfirmation");
    expect(setup.calls.some((call) => call.path === CUSTOMER_STATUS)).toBe(false);
  });

  it("says it did not find the order when the API does not know it", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    guestStatus = () => problem(404, "Nie znaleźliśmy zamówienia.");
    await renderPage();

    expect(screen.getByText("Nie znaleźliśmy zamówienia.")).toBeTruthy();
    expect(screen.queryByTestId("order-number")).toBeNull();
  });

  it.each([
    ["answers 503", () => problem(503)],
    ["answers 500", () => problem(500)],
    ["answers garbage", () => Response.json({ nothing: true })],
    ["cannot be reached", () => {
      throw new TypeError("fetch failed");
    }],
  ])("shows the number and the total of the cookie and offers a check when the API %s", async (_name, respond) => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    setup = cartTestDeps();
    vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
    setup.answer("GET", PROVIDERS, () => Response.json([]));
    setup.answer("GET", GUEST_STATUS, respond);
    await renderPage();

    expect(screen.getByText("Nie udało się sprawdzić statusu płatności.")).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
    expect(screen.getByRole("button", { name: "Sprawdź ponownie" })).toBeTruthy();
  });

  it("shows the order of the cookie when the dependencies of the API cannot be set up", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    vi.mocked(getCartDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
    await renderPage();

    expect(screen.getByText("Nie udało się sprawdzić statusu płatności.")).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
  });

  it("offers a new payment with no providers when the list cannot be read", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    setup = cartTestDeps();
    vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
    setup.answer("GET", PROVIDERS, () => problem(503));
    setup.answer("GET", GUEST_STATUS, () => Response.json(statusView({ latestPayment: null })));
    await renderPage();

    expect(screen.getByText("Płatności są chwilowo niedostępne. Spróbuj za chwilę.")).toBeTruthy();
  });

  it("says it found nothing without the cookie", async () => {
    withConfirmation(undefined);
    await renderPage();

    expectNotFound();
  });

  it.each([
    ["signed with another secret", signValue(encoded(guestConfirmation), "x".repeat(64))],
    ["unsigned", encoded(guestConfirmation)],
    ["with a forged signature", `${encoded({ ...guestConfirmation, totalGross: 1 })}.${signedConfirmationValue(guestConfirmation).split(".")[1]}`],
    ["signed, but not a confirmation", signValue(encoded({ number: "R-100001" }), testConfig.sessionSecret)],
    ["signed, of an older shape without the order id", signValue(encoded({ number: "R-100001", email: "a@b.pl", totalGross: 1, currency: "PLN" }), testConfig.sessionSecret)],
    ["signed, but not JSON", signValue("bm90IGpzb24", testConfig.sessionSecret)],
  ])("says it found nothing with a cookie %s", async (_name, value) => {
    withConfirmation(value);
    await renderPage();

    expectNotFound();
  });

  it("says it found nothing when the store is not configured", async () => {
    withConfirmation(signedConfirmationValue(guestConfirmation));
    vi.mocked(readAuthConfig).mockImplementation(() => {
      throw new Error("Missing environment variables: STORE_SESSION_SECRET.");
    });
    await renderPage();

    expectNotFound();
  });
});
