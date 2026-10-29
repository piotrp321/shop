import { NextResponse } from "next/server";
import { cookieOptions, LOGIN_COOKIE, loginCookieOptions, SESSION_COOKIE } from "./cookies";

/** Every `/auth/*` response is personal and must never be stored by a browser or a proxy. */
export function noStore<T extends Response>(response: T): T {
  response.headers.set("Cache-Control", "private, no-store");
  return response;
}

/** 303: the browser follows with a GET, also after the POST of the logout form. */
export const redirectTo = (url: URL | string) => noStore(NextResponse.redirect(url, 303));

export const json = (body: unknown, status = 200) => noStore(NextResponse.json(body, { status }));

export function clearSessionCookie(response: NextResponse, storeUrl: string) {
  response.cookies.set(SESSION_COOKIE, "", { ...cookieOptions(storeUrl), maxAge: 0 });
  return response;
}

export function clearLoginCookie(response: NextResponse, storeUrl: string) {
  response.cookies.set(LOGIN_COOKIE, "", { ...loginCookieOptions(storeUrl), maxAge: 0 });
  return response;
}

/**
 * Logs name, code and message only, never the error object: openid-client puts whole token responses into
 * `cause`. The library's messages are fixed texts without request data.
 */
export function logFailure(context: string, error: unknown) {
  if (!(error instanceof Error)) {
    console.error(`${context}: ${typeof error}`);
    return;
  }
  const code = "code" in error ? ` [${String(error.code)}]` : "";
  console.error(`${context}: ${error.name}${code}: ${error.message}`);
}
