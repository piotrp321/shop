import * as OTPAuth from "otpauth";

const PERIOD_SECONDS = 30;
// A code generated this close to the end of its window may expire before Keycloak checks it.
const MIN_REMAINING_MS = 3000;

/** Current TOTP code (SHA1, 6 digits, 30 s) for a raw UTF-8 secret; waits for a fresh window when the current one is nearly over. */
export async function totp(rawSecret: string): Promise<string> {
  const generator = new OTPAuth.TOTP({
    secret: OTPAuth.Secret.fromUTF8(rawSecret),
    algorithm: "SHA1",
    digits: 6,
    period: PERIOD_SECONDS,
  });
  const periodMs = PERIOD_SECONDS * 1000;
  const remainingMs = periodMs - (Date.now() % periodMs);
  if (remainingMs < MIN_REMAINING_MS) {
    await new Promise((resolve) => setTimeout(resolve, remainingMs + 100));
  }
  return generator.generate();
}
