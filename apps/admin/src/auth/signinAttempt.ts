/**
 * One-shot marker in sessionStorage: "a sign-in redirect was started because the API answered 401".
 * It survives the round trip through Keycloak and is removed by the first successful API response, which bounds a
 * persistent 401 (clock skew, issuer or audience mismatch) to one redirect per login cycle instead of a loop.
 * It holds a timestamp, never a token.
 */
export const SIGNIN_ATTEMPT_KEY = "admin.signin-attempt";

export function markSigninAttempt(): void {
  try {
    sessionStorage.setItem(SIGNIN_ATTEMPT_KEY, String(Date.now()));
  } catch {
    // Storage blocked: the in-page flag of the API client still allows one redirect per page load.
  }
}

export function hasSigninAttempt(): boolean {
  try {
    return sessionStorage.getItem(SIGNIN_ATTEMPT_KEY) !== null;
  } catch {
    return false;
  }
}

export function clearSigninAttempt(): void {
  try {
    sessionStorage.removeItem(SIGNIN_ATTEMPT_KEY);
  } catch {
    // Nothing to clear.
  }
}
