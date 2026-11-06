// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { cookies } from "next/headers";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { signValue } from "@/auth/cookies";
import { readAuthConfig } from "@/auth/config";
import { testConfig } from "@/auth/test-support";
import { formatPrice } from "@/catalog/format-price";
import OrderConfirmationPage, { metadata } from "./page";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));
vi.mock("@/auth/config", () => ({ readAuthConfig: vi.fn() }));

const COOKIE = "store_order_confirmation";
const confirmation = {
  orderId: "0199a0b0-0000-7000-8000-000000000001",
  number: "R-100001",
  email: "anna.nowak@example.com",
  totalGross: 39998,
  currency: "PLN",
  accessToken: "dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM",
};
const encoded = (value: unknown) => Buffer.from(JSON.stringify(value)).toString("base64url");

const withCookie = (value: string | undefined) =>
  vi.mocked(cookies).mockResolvedValue({
    get: (name: string) => (name === COOKIE && value !== undefined ? { name, value } : undefined),
  } as unknown as Awaited<ReturnType<typeof cookies>>);

beforeEach(() => {
  vi.mocked(readAuthConfig).mockReturnValue(testConfig);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(cleanup);

const renderPage = async () => render(await OrderConfirmationPage());

const expectNotFound = () => {
  expect(screen.getByText("Nie znaleźliśmy informacji o zamówieniu.")).toBeTruthy();
  expect(screen.queryByText("Dziękujemy za zamówienie")).toBeNull();
  expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
};

describe("the order confirmation page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("shows the number, the e-mail and the gross total of the signed cookie", async () => {
    withCookie(signValue(encoded(confirmation), testConfig.sessionSecret));
    await renderPage();

    expect(screen.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeTruthy();
    expect(screen.getByTestId("order-number").textContent).toBe("R-100001");
    expect(screen.getByText("Potwierdzenie wysłaliśmy na adres anna.nowak@example.com.")).toBeTruthy();
    expect(screen.getByTestId("order-total").textContent).toBe(formatPrice(39998, "PLN"));
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
  });

  it("says it found nothing without the cookie", async () => {
    withCookie(undefined);
    await renderPage();

    expectNotFound();
  });

  it.each([
    ["signed with another secret", signValue(encoded(confirmation), "x".repeat(64))],
    ["unsigned", encoded(confirmation)],
    ["with a forged signature", `${encoded({ ...confirmation, totalGross: 1 })}.${signValue(encoded(confirmation), testConfig.sessionSecret).split(".")[1]}`],
    ["signed, but not a confirmation", signValue(encoded({ number: "R-100001" }), testConfig.sessionSecret)],
    ["signed, but not JSON", signValue("bm90IGpzb24", testConfig.sessionSecret)],
  ])("says it found nothing with a cookie %s", async (_name, value) => {
    withCookie(value);
    await renderPage();

    expectNotFound();
  });

  it("says it found nothing when the store is not configured", async () => {
    withCookie(signValue(encoded(confirmation), testConfig.sessionSecret));
    vi.mocked(readAuthConfig).mockImplementation(() => {
      throw new Error("Missing environment variables: STORE_SESSION_SECRET.");
    });
    await renderPage();

    expectNotFound();
  });
});
