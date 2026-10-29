import type { NextRequest } from "next/server";
import type { AccountSession } from "@/api/client";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { clearSessionCookie, json, logFailure } from "@/auth/responses";

/**
 * `{ name }` of the signed-in customer or `null`, from `GET /api/v1/me` called with the session's (refreshed)
 * access token. Only a bad token ends the session; an API or Redis failure answers 503 and keeps it.
 */
export async function GET(request: NextRequest) {
  const { config, sessions, freshAccessToken, fetchMe } = await getAuthDeps();
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
}
