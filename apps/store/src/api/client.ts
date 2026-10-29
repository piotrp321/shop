import createClient, { type Client } from "openapi-fetch";
import type { paths } from "./schema";

export type ApiClient = Client<paths>;

/**
 * The only place that talks to the API. Use it from the server only: `baseUrl` is the internal address.
 * `accessToken` adds a bearer header for endpoints that need a signed-in user.
 */
export function createApiClient(options: { baseUrl: string; accessToken?: string }): ApiClient {
  return createClient<paths>({
    baseUrl: options.baseUrl,
    headers: options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : undefined,
  });
}

/** Body of the store's own `GET /auth/session` (route handler in app/auth/session): never tokens. */
export type AccountSession = { name: string } | null;

export type AccountSessionResult = { status: "ok"; session: AccountSession } | { status: "unavailable" };

/**
 * For the browser: asks the store's `/auth/session` (same origin, so the session cookie goes along) who is signed
 * in. It does not talk to the API; the store's server does that with the token from the session.
 */
export async function loadAccountSession(): Promise<AccountSessionResult> {
  try {
    const response = await fetch("/auth/session", { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) {
      return { status: "unavailable" };
    }
    return { status: "ok", session: (await response.json()) as AccountSession };
  } catch {
    return { status: "unavailable" };
  }
}
