// Doubles for the tests of the order history. Never imported by application code.
import { vi } from "vitest";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { AuthDeps } from "@/auth/deps";
import { testDeps } from "@/auth/test-support";

export type ApiRequest = { method: string; path: string; search: string; authorization: string | null };

export const ORDER_ID = "0199a0b0-0000-7000-8000-0000000000aa";

/** The auth dependencies of the test doubles with an API client on a fake network that records every request. */
export function accountTestDeps() {
  const setup = testDeps();
  const requests: ApiRequest[] = [];
  const handlers: ((request: ApiRequest) => Response | undefined)[] = [];
  const deps: AuthDeps = {
    ...setup.deps,
    api: (accessToken?: string) =>
      createApiClient({
        baseUrl: "http://api",
        accessToken,
        fetch: async (request) => {
          const url = new URL(request.url);
          const call = { method: request.method, path: url.pathname, search: url.search, authorization: request.headers.get("authorization") };
          requests.push(call);
          for (const handler of handlers) {
            const response = handler(call);
            if (response) return response;
          }
          throw new Error(`Unexpected API call: ${call.method} ${call.path}`);
        },
      }),
  };
  return {
    deps,
    sessions: setup.sessions,
    requests,
    /** Answers `GET path` (query-less) with a fresh response. */
    answer: (path: string, respond: (request: ApiRequest) => Response | Promise<Response>) =>
      void handlers.push((call) => (call.method === "GET" && call.path === path ? (respond(call) as Response) : undefined)),
  };
}

export const problem = (status: number, detail?: string) =>
  Response.json(
    { type: "about:blank", title: "Problem", status, ...(detail === undefined ? {} : { detail }) },
    { status, headers: { "Content-Type": "application/problem+json" } },
  );

export type OrderListItem = components["schemas"]["CustomerOrderListItem"];
export type OrderDetail = components["schemas"]["CustomerOrder"];

export const listItem = (overrides: Partial<OrderListItem> = {}): OrderListItem => ({
  orderId: ORDER_ID,
  number: "R-100001",
  placedAt: "2026-11-05T12:00:00+00:00",
  status: "paid",
  totalGross: 39998,
  currency: "PLN",
  itemCount: 3,
  // 12:30 UTC is 13:30 in Warsaw in November.
  paymentDeadline: "2026-11-05T12:30:00+00:00",
  paymentDeadlinePassed: false,
  ...overrides,
});

export const detail = (overrides: Partial<OrderDetail> = {}): OrderDetail => ({
  orderId: ORDER_ID,
  number: "R-100001",
  status: "paid",
  shippingAddress: { street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa", country: "PL" },
  lines: [
    { variantId: "00000000-0000-4000-8000-000000000001", productName: "Buty szosowe Tarvik Asfalt 200", variantLabel: "czarne, 42", quantity: 2, unitGross: 19999, grossBeforeDiscount: 39998, discountGross: 0, lineGross: 39998 },
    { variantId: "00000000-0000-4000-8000-000000000002", productName: "Czapka", variantLabel: "", quantity: 1, unitGross: 2000, grossBeforeDiscount: 2000, discountGross: 0, lineGross: 2000 },
  ],
  discount: null,
  totals: { net: 34145, vat: 7853, gross: 41998, currency: "PLN" },
  placedAt: "2026-11-05T12:00:00+00:00",
  paymentDeadline: "2026-11-05T12:30:00+00:00",
  paymentDeadlinePassed: false,
  paidAt: "2026-11-05T12:10:00+00:00",
  shippedAt: null,
  deliveredAt: null,
  cancelledAt: null,
  returnedAt: null,
  ...overrides,
});

export const quiet = () => vi.spyOn(console, "error").mockImplementation(() => {});
