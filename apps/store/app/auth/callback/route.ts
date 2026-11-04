import { cookieOptions, LOGIN_COOKIE, readSignedValue, SESSION_COOKIE, signValue } from "@/auth/cookies";
import { sessionFromTokens } from "@/auth/refresh";
import { clearLoginCookie, logFailure, redirectTo, withAuthDeps } from "@/auth/responses";
import { sanitizeReturnTo } from "@/auth/return-to";
import { CART_COOKIE } from "@/cart/cookies";
import { applyGuestCartMerge, mergeGuestCart } from "@/cart/merge";

/**
 * Finishes the login: takes the transaction (once), checks state, exchanges the code and validates the id_token,
 * then starts a new session under a new id, merges the guest cart of `store_cart` into the customer's and returns to
 * `returnTo`. Any failure of the login lands on `/` without a session; a failed merge does not stop the login.
 */
export const GET = withAuthDeps(async (request, { config, oidc, sessions, transactions, api }) => {
  const home = new URL("/", config.storeUrl);
  const query = request.nextUrl.searchParams;

  try {
    const transactionId = readSignedValue(request.cookies.get(LOGIN_COOKIE)?.value, config.sessionSecret);
    const transaction = transactionId ? await transactions.take(transactionId) : null;
    if (!transaction || query.get("state") !== transaction.state || !query.has("code")) {
      return clearLoginCookie(redirectTo(home), config.storeUrl);
    }

    const { state, nonce, codeVerifier } = transaction;
    const { sub, tokens } = await (await oidc()).exchangeCode(query, { state, nonce, codeVerifier });

    // A login always gets a fresh session id; whatever session the browser brought is ended (session fixation).
    const previousId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, config.sessionSecret);
    if (previousId) {
      await sessions.delete(previousId);
    }
    const sessionId = await sessions.create(sessionFromTokens(sub, tokens, Date.now()));
    // Never throws; a failed merge keeps `store_cart` and the first request of the cart tries again.
    const merge = await mergeGuestCart(request.cookies.get(CART_COOKIE)?.value, config.sessionSecret, api(tokens.accessToken));

    const response = redirectTo(new URL(sanitizeReturnTo(transaction.returnTo, config.storeUrl), config.storeUrl));
    response.cookies.set(SESSION_COOKIE, signValue(sessionId, config.sessionSecret), cookieOptions(config.storeUrl));
    applyGuestCartMerge(response, merge, config);
    return clearLoginCookie(response, config.storeUrl);
  } catch (error) {
    logFailure("Login callback failed", error);
    return clearLoginCookie(redirectTo(home), config.storeUrl);
  }
});
