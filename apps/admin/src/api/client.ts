import type { UserManager } from "oidc-client-ts";
import createClient, { type Client, type Middleware } from "openapi-fetch";
import { getUserManager } from "@/auth/userManager";
import { requiredUrl } from "@/auth/config";
import type { paths } from "./schema";

export type ApiClient = Client<paths>;

export interface ApiClientOptions {
  baseUrl: string;
  userManager: Pick<UserManager, "getUser" | "signinRedirect">;
  fetch?: (request: Request) => Promise<Response>;
}

/**
 * The only place that talks to the API. Adds the bearer token of the signed-in user to every request;
 * a 401 answer sends the user to sign in again (once, however many requests fail together).
 */
export function createApiClient(options: ApiClientOptions): ApiClient {
  const { userManager } = options;
  let redirecting = false;

  const middleware: Middleware = {
    async onRequest({ request }) {
      const user = await userManager.getUser();
      if (user) {
        request.headers.set("Authorization", `Bearer ${user.access_token}`);
      }
      return request;
    },
    async onResponse({ response }) {
      if (response.status === 401 && !redirecting) {
        redirecting = true;
        await userManager.signinRedirect({ state: { returnTo: `${location.pathname}${location.search}` } });
      }
      return response;
    },
  };

  const client = createClient<paths>({ baseUrl: options.baseUrl, fetch: options.fetch });
  client.use(middleware);
  return client;
}

let instance: ApiClient | undefined;

export function getApiClient(): ApiClient {
  instance ??= createApiClient({
    baseUrl: requiredUrl(import.meta.env, "VITE_API_URL"),
    userManager: getUserManager(),
  });
  return instance;
}
