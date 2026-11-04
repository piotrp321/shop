import { type ApiClient, createApiClient } from "@/api/client";
import { type AuthDeps, getAuthDeps } from "@/auth/deps";

/** Everything the `/api/cart/*` route handlers need; replaced by doubles in their tests. */
export type CartDeps = Pick<AuthDeps, "config" | "sessions" | "freshAccessToken"> & {
  /** The API client, with the customer's access token when there is one. */
  api: (accessToken?: string) => ApiClient;
};

/** Shares sessions, token refresh and configuration with the login. */
export async function getCartDeps(): Promise<CartDeps> {
  const { config, sessions, freshAccessToken } = await getAuthDeps();
  return {
    config,
    sessions,
    freshAccessToken,
    api: (accessToken) => createApiClient({ baseUrl: config.apiInternalUrl, accessToken }),
  };
}
