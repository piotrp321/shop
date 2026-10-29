import type { NextRequest } from "next/server";
import { LOGIN_COOKIE, loginCookieOptions, signValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { logFailure, noStore, redirectTo } from "@/auth/responses";
import { sanitizeReturnTo } from "@/auth/return-to";

/**
 * Starts the login: state, nonce and PKCE verifier go into a single-use Redis transaction (10 min), its signed id
 * into a short cookie for the callback, and the browser to Keycloak's public authorization endpoint.
 */
export async function GET(request: NextRequest) {
  const { config, oidc, transactions } = await getAuthDeps();
  try {
    const login = await (await oidc()).startLogin();
    const returnTo = sanitizeReturnTo(request.nextUrl.searchParams.get("returnTo"), config.storeUrl);
    const id = await transactions.create({
      state: login.state,
      nonce: login.nonce,
      codeVerifier: login.codeVerifier,
      returnTo,
    });

    const response = redirectTo(login.url);
    response.cookies.set(LOGIN_COOKIE, signValue(id, config.sessionSecret), loginCookieOptions(config.storeUrl));
    return response;
  } catch (error) {
    logFailure("Login could not start", error);
    return noStore(
      new Response("Logowanie jest chwilowo niedostępne. Spróbuj ponownie za chwilę.", {
        status: 503,
        headers: { "Content-Type": "text/plain; charset=utf-8" },
      }),
    );
  }
}
