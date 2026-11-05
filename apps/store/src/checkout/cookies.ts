import type { NextResponse } from "next/server";
import { cookieOptions, readSignedValue, signValue } from "@/auth/cookies";

/**
 * Holds what the confirmation page shows of the order just placed, signed like `store_cart` (the signature proves the
 * store issued it; it does not hide it). The guest has no other way to read the order (decision P7).
 */
export const CONFIRMATION_COOKIE = "store_order_confirmation";
export const CONFIRMATION_COOKIE_MAX_AGE_SECONDS = 60 * 60;

/** Copied from the `OrderView` of the API; nothing here is computed by the store. */
export type OrderConfirmation = { number: string; email: string; totalGross: number; currency: string };

// The JSON goes in base64url: a signed value is `<value>.<signature>`, and an e-mail address has dots.
const encode = (confirmation: OrderConfirmation) => Buffer.from(JSON.stringify(confirmation)).toString("base64url");

function decode(value: string): OrderConfirmation | null {
  try {
    const data: unknown = JSON.parse(Buffer.from(value, "base64url").toString("utf8"));
    if (typeof data !== "object" || data === null) return null;
    const { number, email, totalGross, currency } = data as Record<string, unknown>;
    if (typeof number !== "string" || typeof email !== "string" || !Number.isInteger(totalGross) || typeof currency !== "string") {
      return null;
    }
    return { number, email, totalGross: totalGross as number, currency };
  } catch {
    return null;
  }
}

export function setOrderConfirmation(response: NextResponse, confirmation: OrderConfirmation, config: { storeUrl: string; sessionSecret: string }) {
  response.cookies.set(CONFIRMATION_COOKIE, signValue(encode(confirmation), config.sessionSecret), {
    ...cookieOptions(config.storeUrl),
    maxAge: CONFIRMATION_COOKIE_MAX_AGE_SECONDS,
  });
}

/** The confirmation of a `store_order_confirmation` cookie, or null when it is missing, forged or malformed. */
export function readOrderConfirmation(signed: string | undefined, secret: string): OrderConfirmation | null {
  const value = readSignedValue(signed, secret);
  return value === null ? null : decode(value);
}
