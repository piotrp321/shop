import type { AccountSession } from "@/api/client";
import { buyerTag } from "@/auth/buyer";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, withAuthDeps } from "@/auth/responses";

/**
 * `{ name, email, buyer }` of the signed-in customer or `null`: `name` and `email` from `GET /api/v1/me` called with the
 * session's (refreshed) access token, `buyer` the tag of the `sub` of the same session (`buyerTag`), which the checkout
 * sends back with the order. Never the sub, the session id or a token. Only a bad or unrefreshable token ends the
 * session; an API, Redis or Keycloak failure (also a refresh that cannot be done right now) answers 503 and keeps it.
 */
export const GET = withAuthDeps(async (request, { config, sessions, freshAccessToken, fetchMe }) => {
  const signedOut = () => clearSessionCookie(json(null satisfies AccountSession), config.storeUrl);

  const cookie = request.cookies.get(SESSION_COOKIE)?.value;
  if (cookie === undefined) {
    return json(null satisfies AccountSession);
  }
  const sessionId = readSignedValue(cookie, config.sessionSecret);
  if (!sessionId) {
    return signedOut();
  }

  try {
    const accessToken = await freshAccessToken(sessionId);
    if (!accessToken) {
      await sessions.delete(sessionId);
      return signedOut();
    }
    // The sub of the session the token came from (a refresh keeps it); a session deleted meanwhile is signed out.
    const session = await sessions.get(sessionId);
    if (!session) {
      return signedOut();
    }

    const me = await fetchMe(accessToken);
    switch (me.kind) {
      case "ok":
        return json({ name: me.name, email: me.email, buyer: buyerTag(session.sub, config.sessionSecret) } satisfies AccountSession);
      case "unauthorized":
        await sessions.delete(sessionId);
        return signedOut();
      case "unavailable":
        return json(null, 503);
    }
  } catch (error) {
    logFailure("Session check failed", error);
    return json(null, 503);
  }
});
