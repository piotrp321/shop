import { type AuthDeps, getAuthDeps } from "@/auth/deps";

/** Everything the `/api/cart/*` route handlers need; replaced by doubles in their tests. */
export type CartDeps = Pick<AuthDeps, "config" | "sessions" | "freshAccessToken" | "api">;

/** Shares sessions, token refresh, the API client and configuration with the login. */
export async function getCartDeps(): Promise<CartDeps> {
  const { config, sessions, freshAccessToken, api } = await getAuthDeps();
  return { config, sessions, freshAccessToken, api };
}
