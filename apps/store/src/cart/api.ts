import type { ApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type CartView = components["schemas"]["CartView"];
export type CartSummary = components["schemas"]["CartSummary"];
export type MergeResult = components["schemas"]["MergeResult"];

/** An answer of the API: its data, or the status (and the `detail` of its problem document) of a refusal. */
export type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number; detail?: string; violation?: string };

/** The cart operations the BFF needs, the same for a guest cart and for the cart of the signed-in customer. */
export type CartApi = {
  /** `discountCode` is the code of the checkout preview; the API judges it and answers with it applied or rejected. */
  get(discountCode?: string): Promise<ApiResult<CartView>>;
  summary(): Promise<ApiResult<CartSummary>>;
  add(variantId: string, quantity: number): Promise<ApiResult<CartView>>;
  change(variantId: string, quantity: number): Promise<ApiResult<CartView>>;
  remove(variantId: string): Promise<ApiResult<CartView>>;
};

// A cart is personal and changes with every request: never served from a fetch cache.
const init = { cache: "no-store" } as const;

type Answer<T> = { data?: T; error?: unknown; response: Response };

function result<T>({ data, error, response }: Answer<T>): ApiResult<T> {
  if (data !== undefined) {
    return { ok: true, data };
  }
  const problem = typeof error === "object" && error !== null ? (error as { detail?: unknown; violations?: unknown }) : {};
  const first = Array.isArray(problem.violations) ? (problem.violations[0] as { title?: unknown } | undefined) : undefined;
  return {
    ok: false,
    status: response.status,
    detail: typeof problem.detail === "string" ? problem.detail : undefined,
    violation: typeof first?.title === "string" ? first.title : undefined,
  };
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
    async get(discountCode) {
      return result(await client.GET("/api/v1/me/cart", { ...init, params: { query: { discountCode } } }));
    },
    async summary() {
      return result(await client.GET("/api/v1/me/cart/summary", init));
    },
    async add(variantId, quantity) {
      return result(await client.POST("/api/v1/me/cart/items", { ...init, body: { variantId, quantity } }));
    },
    async change(variantId, quantity) {
      return result(
        await client.PUT("/api/v1/me/cart/items/{variantId}", { ...init, params: { path: { variantId } }, body: { quantity } }),
      );
    },
    async remove(variantId) {
      return result(await client.DELETE("/api/v1/me/cart/items/{variantId}", { ...init, params: { path: { variantId } } }));
    },
  };
}

/** A guest cart; its id is the key to it, so no token is sent. */
export function guestCart(client: ApiClient, cartId: string): CartApi {
  const path = { cartId };
  return {
    async get(discountCode) {
      return result(await client.GET("/api/v1/guest-carts/{cartId}", { ...init, params: { path, query: { discountCode } } }));
    },
    async summary() {
      return result(await client.GET("/api/v1/guest-carts/{cartId}/summary", { ...init, params: { path } }));
    },
    async add(variantId, quantity) {
      return result(
        await client.POST("/api/v1/guest-carts/{cartId}/items", { ...init, params: { path }, body: { variantId, quantity } }),
      );
    },
    async change(variantId, quantity) {
      return result(
        await client.PUT("/api/v1/guest-carts/{cartId}/items/{variantId}", {
          ...init,
          params: { path: { cartId, variantId } },
          body: { quantity },
        }),
      );
    },
    async remove(variantId) {
      return result(
        await client.DELETE("/api/v1/guest-carts/{cartId}/items/{variantId}", { ...init, params: { path: { cartId, variantId } } }),
      );
    },
  };
}
