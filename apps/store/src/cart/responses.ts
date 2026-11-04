import type { NextRequest } from "next/server";
import { logFailure, noStore } from "@/auth/responses";
import { type CartDeps, getCartDeps } from "./deps";

/**
 * Runs a `/api/cart/*` handler with its dependencies. When they cannot be set up (missing configuration, for
 * example) the answer is still a 503 with `no-store` instead of Next's bare 500.
 */
export function withCartDeps<Context = void>(handler: (request: NextRequest, deps: CartDeps, context: Context) => Promise<Response>) {
  // Routes without path parameters are called without a context in tests; Next always passes one.
  return async (request: NextRequest, ...[context]: Context extends void ? [context?: unknown] : [context: Context]): Promise<Response> => {
    let deps: CartDeps;
    try {
      deps = await getCartDeps();
    } catch (error) {
      logFailure("The cart is not configured", error);
      return noStore(new Response(null, { status: 503 }));
    }
    return handler(request, deps, context as Context);
  };
}
