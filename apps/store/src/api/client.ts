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
