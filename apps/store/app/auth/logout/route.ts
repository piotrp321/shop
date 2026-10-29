import type { NextRequest } from "next/server";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, logFailure, noStore, redirectTo, withAuthDeps } from "@/auth/responses";

/**
 * SameSite=Lax already keeps the session cookie off cross-site POSTs. On top of that a request whose `Origin` is
 * not the store, or that the browser marks as not same-origin (`Sec-Fetch-Site: cross-site` or `same-site`, e.g.
 * the admin panel on another port of the same host), is refused. Requests without either header (non-browser
 * clients, old browsers) are let through.
 */
function isSameOrigin(request: NextRequest, storeUrl: string): boolean {
  const origin = request.headers.get("origin");
  if (origin !== null && origin !== new URL(storeUrl).origin) {
    return false;
  }
  const site = request.headers.get("sec-fetch-site");
  return site !== "cross-site" && site !== "same-site";
}

/** Logout is a POST; a GET (link, prefetch, image) changes nothing. */
export function GET() {
  return noStore(new Response(null, { status: 405, headers: { Allow: "POST" } }));
}

/**
 * POST only (the account menu's form). Deletes the session and its cookie, then sends the browser to Keycloak's
 * end_session endpoint with `id_token_hint`, which ends the Keycloak session and returns to the store.
 */
export const POST = withAuthDeps(async (request, { config, oidc, sessions }) => {
  if (!isSameOrigin(request, config.storeUrl)) {
    return noStore(new Response(null, { status: 403 }));
  }

  let target = new URL("/", config.storeUrl);
  try {
    const sessionId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, config.sessionSecret);
    const session = sessionId ? await sessions.get(sessionId) : null;
    if (sessionId) {
      await sessions.delete(sessionId);
    }
    if (session) {
      target = (await oidc()).endSessionUrl(session.idToken);
    }
  } catch (error) {
    // Redis or Keycloak unavailable: the cookie still goes, the Redis key expires with the refresh token.
    logFailure("Logout incomplete", error);
  }
  return clearSessionCookie(redirectTo(target), config.storeUrl);
});
