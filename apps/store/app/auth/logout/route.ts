import type { NextRequest } from "next/server";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { clearSessionCookie, logFailure, noStore, redirectTo } from "@/auth/responses";

/**
 * POST only (the account menu's form). Deletes the session and its cookie, then sends the browser to Keycloak's
 * end_session endpoint with `id_token_hint`, which ends the Keycloak session and returns to the store.
 */
export async function POST(request: NextRequest) {
  const { config, oidc, sessions } = await getAuthDeps();

  // SameSite=Lax already keeps the cookie off cross-site POSTs; a foreign Origin is refused outright.
  const origin = request.headers.get("origin");
  if (origin !== null && origin !== new URL(config.storeUrl).origin) {
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
}
