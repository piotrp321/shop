import type { ApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type CartView = components["schemas"]["CartView"];
export type CartSummary = components["schemas"]["CartSummary"];
export type MergeResult = components["schemas"]["MergeResult"];

/** An answer of the API: its data, or the status (and the `detail` of its problem document) of a refusal. */
export type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number; detail?: string };

/** The cart operations the BFF needs, the same for a guest cart and for the cart of the signed-in customer. */
export type CartApi = {
  get(): Promise<ApiResult<CartView>>;
  summary(): Promise<ApiResult<CartSummary>>;
  add(productId: string, quantity: number): Promise<ApiResult<CartView>>;
  change(productId: string, quantity: number): Promise<ApiResult<CartView>>;
  remove(productId: string): Promise<ApiResult<CartView>>;
};

// A cart is personal and changes with every request: never served from a fetch cache.
const init = { cache: "no-store" } as const;

type Answer<T> = { data?: T; error?: unknown; response: Response };

function result<T>({ data, error, response }: Answer<T>): ApiResult<T> {
  if (data !== undefined) {
    return { ok: true, data };
  }
  const detail = typeof error === "object" && error !== null && "detail" in error ? error.detail : undefined;
  return { ok: false, status: response.status, detail: typeof detail === "string" ? detail : undefined };
}

/** `POST /api/v1/guest-carts`: a new, empty cart. */
export async function createGuestCart(client: ApiClient): Promise<ApiResult<CartView>> {
  return result(await client.POST("/api/v1/guest-carts", init));
}

/** `POST /api/v1/me/cart/merge`: moves the guest cart into the cart of the customer whose access token the client carries. */
export async function mergeIntoCustomerCart(client: ApiClient, guestCartId: string): Promise<ApiResult<MergeResult>> {
  return result(await client.POST("/api/v1/me/cart/merge", { ...init, body: { guestCartId } }));
}

/** The cart of the customer whose access token the client carries. */
export function customerCart(client: ApiClient): CartApi {
  return {
    async get() {
      return result(await client.GET("/api/v1/me/cart", init));
    },
    async summary() {
      return result(await client.GET("/api/v1/me/cart/summary", init));
    },
    async add(productId, quantity) {
      return result(await client.POST("/api/v1/me/cart/items", { ...init, body: { productId, quantity } }));
    },
    async change(productId, quantity) {
      return result(
        await client.PUT("/api/v1/me/cart/items/{productId}", { ...init, params: { path: { productId } }, body: { quantity } }),
      );
    },
    async remove(productId) {
      return result(await client.DELETE("/api/v1/me/cart/items/{productId}", { ...init, params: { path: { productId } } }));
    },
  };
}

/** A guest cart; its id is the key to it, so no token is sent. */
export function guestCart(client: ApiClient, cartId: string): CartApi {
  const path = { cartId };
  return {
    async get() {
      return result(await client.GET("/api/v1/guest-carts/{cartId}", { ...init, params: { path } }));
    },
    async summary() {
      return result(await client.GET("/api/v1/guest-carts/{cartId}/summary", { ...init, params: { path } }));
    },
    async add(productId, quantity) {
      return result(
        await client.POST("/api/v1/guest-carts/{cartId}/items", { ...init, params: { path }, body: { productId, quantity } }),
      );
    },
    async change(productId, quantity) {
      return result(
        await client.PUT("/api/v1/guest-carts/{cartId}/items/{productId}", {
          ...init,
          params: { path: { cartId, productId } },
          body: { quantity },
        }),
      );
    },
    async remove(productId) {
      return result(
        await client.DELETE("/api/v1/guest-carts/{cartId}/items/{productId}", { ...init, params: { path: { cartId, productId } } }),
      );
    },
  };
}
