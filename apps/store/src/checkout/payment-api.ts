import type { ApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type Refusal, refusalOf } from "./api";

export type PaymentProvider = components["schemas"]["PaymentProvider"];
export type PaymentView = components["schemas"]["PaymentView"];
export type PaymentStatusView = components["schemas"]["PaymentStatusView"];

// Payments are personal and change under the customer's eyes: never served from a fetch cache.
const init = { cache: "no-store" } as const;

/** `GET /api/v1/payment-providers`: the providers a customer can choose; null when the API cannot tell. */
export async function listPaymentProviders(client: ApiClient): Promise<PaymentProvider[] | null> {
  try {
    const { data } = await client.GET("/api/v1/payment-providers", init);
    return Array.isArray(data) ? data : null;
  } catch {
    return null;
  }
}

/** A payment (201 started, 200 repeated request), or the status, `type`, `detail` and `violations` of a refusal. */
export type PaymentStartAnswer = { ok: true; status: number; data: PaymentView } | Refusal;

type Answer<T> = { data?: T; error?: unknown; response: Response };

function started({ data, error, response }: Answer<PaymentView>): PaymentStartAnswer {
  return data !== undefined ? { ok: true, status: response.status, data } : refusalOf(error, response);
}

/** `POST /api/v1/orders/{orderId}/payments`: the payment of an order of a guest, who proves it with the access token. */
export async function startGuestPayment(
  client: ApiClient,
  orderId: string,
  accessToken: string,
  idempotencyKey: string,
  provider: string,
): Promise<PaymentStartAnswer> {
  return started(
    await client.POST("/api/v1/orders/{orderId}/payments", {
      ...init,
      params: { path: { orderId }, header: { "Idempotency-Key": idempotencyKey, "Order-Access-Token": accessToken } },
      body: { provider: provider as components["schemas"]["StartPaymentRequest"]["provider"] },
    }),
  );
}

/** `POST /api/v1/me/orders/{orderId}/payments`: the payment of an order of the customer whose token the client carries. */
export async function startCustomerPayment(client: ApiClient, orderId: string, idempotencyKey: string, provider: string): Promise<PaymentStartAnswer> {
  return started(
    await client.POST("/api/v1/me/orders/{orderId}/payments", {
      ...init,
      params: { path: { orderId }, header: { "Idempotency-Key": idempotencyKey } },
      body: { provider: provider as components["schemas"]["StartPaymentRequest"]["provider"] },
    }),
  );
}

export type PaymentStatusAnswer = { ok: true; data: PaymentStatusView } | Refusal;

function read({ data, error, response }: Answer<PaymentStatusView>): PaymentStatusAnswer {
  return data !== undefined ? { ok: true, data } : refusalOf(error, response);
}

/** `GET /api/v1/orders/{orderId}/payment`: the status of the order of a guest. */
export async function readGuestPaymentStatus(client: ApiClient, orderId: string, accessToken: string): Promise<PaymentStatusAnswer> {
  return read(
    await client.GET("/api/v1/orders/{orderId}/payment", {
      ...init,
      params: { path: { orderId }, header: { "Order-Access-Token": accessToken } },
    }),
  );
}

/** `GET /api/v1/me/orders/{orderId}/payment`: the status of the order of the customer whose token the client carries. */
export async function readCustomerPaymentStatus(client: ApiClient, orderId: string): Promise<PaymentStatusAnswer> {
  return read(await client.GET("/api/v1/me/orders/{orderId}/payment", { ...init, params: { path: { orderId } } }));
}
