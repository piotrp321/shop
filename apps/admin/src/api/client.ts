import type { UserManager } from "oidc-client-ts";
import createClient, { type Client, type Middleware } from "openapi-fetch";
import { getUserManager } from "@/auth/userManager";
import { requiredUrl } from "@/auth/config";
import { LOGIN_SERVER_MESSAGE, reportLoginProblem, SESSION_REJECTED_MESSAGE } from "@/auth/loginProblem";
import { clearSigninAttempt, hasSigninAttempt, markSigninAttempt } from "@/auth/signinAttempt";
import type { paths } from "./schema";

export type ApiClient = Client<paths>;

export interface ApiClientOptions {
  baseUrl: string;
  userManager: Pick<UserManager, "getUser" | "signinRedirect">;
  fetch?: (request: Request) => Promise<Response>;
}

/**
 * The only place that talks to the API. Adds the bearer token of the signed-in user to every request;
 * a 401 answer sends the user to sign in again: once per page however many requests fail together, and once per
 * login cycle (a 401 right after that redirect shows a problem instead of looping; a successful response ends the cycle).
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
      if (response.ok) {
        clearSigninAttempt();
      } else if (response.status === 401 && !redirecting) {
        if (hasSigninAttempt()) {
          reportLoginProblem(SESSION_REJECTED_MESSAGE);
          return response;
        }
        redirecting = true;
        markSigninAttempt();
        try {
          await userManager.signinRedirect({ state: { returnTo: `${location.pathname}${location.search}` } });
        } catch {
          redirecting = false;
          clearSigninAttempt();
          reportLoginProblem(LOGIN_SERVER_MESSAGE);
        }
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
