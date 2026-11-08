import type { ApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type OrderView = components["schemas"]["OrderView"];
export type Violation = NonNullable<components["schemas"]["Problem"]["violations"]>[number];

/** An order of the API (201 placed, 200 repeated request), or the status, `type`, `detail` and `violations` of a refusal. */
export type OrderResult = { ok: true; status: number; data: OrderView } | Refusal;

/** The fields of `PlaceCustomerOrderRequest`; the guest's contract adds `cartId` and `email`. */
export const CUSTOMER_ORDER_FIELDS = ["fullName", "phone", "street", "postalCode", "city", "expectedTotalGross", "discountCode"] as const;
export const GUEST_ORDER_FIELDS = ["email", ...CUSTOMER_ORDER_FIELDS] as const;

/**
 * The values of the browser under the names of the contract, as they came: the API checks them and names every wrong
 * field. Any other key (a price, lines, a cart id) is dropped.
 */
export function pickFields(body: Record<string, unknown>, fields: readonly string[]): Record<string, unknown> {
  return Object.fromEntries(fields.filter((field) => field in body).map((field) => [field, body[field]]));
}

// An order is personal: never served from a fetch cache.
const init = { cache: "no-store" } as const;

export type Refusal = { ok: false; status: number; type?: string; detail?: string; violations?: Violation[] };

/** A refusal of the API as the BFF passes it on: the status and the parts of the problem the browser may see. */
export function refusalOf(error: unknown, response: Response): Refusal {
  const problem = typeof error === "object" && error !== null ? (error as { type?: unknown; detail?: unknown; violations?: unknown }) : {};
  return {
    ok: false,
    status: response.status,
    type: typeof problem.type === "string" ? problem.type : undefined,
    detail: typeof problem.detail === "string" ? problem.detail : undefined,
    violations: Array.isArray(problem.violations) ? (problem.violations as Violation[]) : undefined,
  };
}

type Answer = { data?: OrderView; error?: unknown; response: Response };

function result({ data, error, response }: Answer): OrderResult {
  return data !== undefined ? { ok: true, status: response.status, data } : refusalOf(error, response);
}

/** `POST /api/v1/orders`: the order of a guest cart. The values are unchecked here, hence the cast. */
export async function placeGuestOrder(client: ApiClient, idempotencyKey: string, body: Record<string, unknown>): Promise<OrderResult> {
  return result(
    await client.POST("/api/v1/orders", {
      ...init,
      params: { header: { "Idempotency-Key": idempotencyKey } },
      body: body as components["schemas"]["PlaceGuestOrderRequest"],
    }),
  );
}

/** `POST /api/v1/me/orders`: the order of the cart of the customer whose access token the client carries. */
export async function placeCustomerOrder(client: ApiClient, idempotencyKey: string, body: Record<string, unknown>): Promise<OrderResult> {
  return result(
    await client.POST("/api/v1/me/orders", {
      ...init,
      params: { header: { "Idempotency-Key": idempotencyKey } },
      body: body as components["schemas"]["PlaceCustomerOrderRequest"],
    }),
  );
}
