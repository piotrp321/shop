// @vitest-environment jsdom
import { isValidElement } from "react";
import { cookies } from "next/headers";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { getCartDeps } from "@/cart/deps";
import { cartTestDeps, confirmationCookie, customerConfirmation, guestConfirmation } from "@/cart/test-support";
import { CheckoutPage } from "@/checkout/CheckoutPage";
import Checkout, { metadata } from "./page";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));
vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

const PROVIDERS = [
  { code: "demo-transfer", name: "Szybki przelew (demo)" },
  { code: "demo-card", name: "Karta płatnicza (demo)" },
];

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  setup.answer("GET", "/api/v1/payment-providers", () => Response.json(PROVIDERS));
  vi.spyOn(console, "error").mockImplementation(() => {});
  withCookie(undefined);
});

function withCookie(header: string | undefined) {
  const at = header?.indexOf("=") ?? -1;
  const [name, value] = header === undefined ? [undefined, undefined] : [header.slice(0, at), header.slice(at + 1)];
  vi.mocked(cookies).mockResolvedValue({
    get: (wanted: string) => (wanted === name && value !== undefined ? { name: wanted, value } : undefined),
  } as unknown as Awaited<ReturnType<typeof cookies>>);
}

/** The props the page gives to the checkout. */
async function propsOfPage() {
  const element = await Checkout();
  expect(isValidElement(element) && element.type).toBe(CheckoutPage);
  return (element as { props: Parameters<typeof CheckoutPage>[0] }).props;
}

describe("the checkout page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("has its own title", () => {
    expect(metadata.title).toBe("Kasa");
  });

  it("gives the checkout the providers of the API", async () => {
    expect(await propsOfPage()).toEqual({ providers: PROVIDERS, pendingOrderNumber: null });
  });

  it.each([
    ["a guest order", guestConfirmation],
    ["a customer's order", customerConfirmation],
  ])("gives the checkout the number of the order of the confirmation cookie (%s)", async (_name, confirmation) => {
    withCookie(confirmationCookie(confirmation));

    expect((await propsOfPage()).pendingOrderNumber).toBe("R-100001");
  });

  it("ignores a confirmation cookie signed with another secret", async () => {
    withCookie(confirmationCookie(guestConfirmation, "x".repeat(64)));

    expect((await propsOfPage()).pendingOrderNumber).toBeNull();
  });

  it("gives the checkout no providers when the API cannot tell", async () => {
    setup = cartTestDeps();
    vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
    setup.answer("GET", "/api/v1/payment-providers", () => new Response(null, { status: 503 }));

    expect(await propsOfPage()).toEqual({ providers: null, pendingOrderNumber: null });
  });

  it("gives the checkout no providers when the store is not configured", async () => {
    vi.mocked(getCartDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));

    expect(await propsOfPage()).toEqual({ providers: null, pendingOrderNumber: null });
  });
});

