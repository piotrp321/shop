import type { AccountSession } from "@/api/client";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, withAuthDeps } from "@/auth/responses";

/**
 * `{ name }` of the signed-in customer or `null`, from `GET /api/v1/me` called with the session's (refreshed)
 * access token. Only a bad or unrefreshable token ends the session; an API, Redis or Keycloak failure (also a
 * refresh that cannot be done right now) answers 503 and keeps it.
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

    const me = await fetchMe(accessToken);
    switch (me.kind) {
      case "ok":
        return json({ name: me.name } satisfies AccountSession);
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
