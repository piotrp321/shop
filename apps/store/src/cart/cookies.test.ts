import { describe, expect, it } from "vitest";
import { signValue } from "@/auth/cookies";
import { CART_COOKIE_MAX_AGE_SECONDS, cartCookieOptions, readCartId } from "./cookies";

const SECRET = "s".repeat(64);
const ID = "11111111-1111-4111-8111-111111111111";

describe("cartCookieOptions", () => {
  it("is HttpOnly, SameSite=Lax, Path=/ and lives 30 days", () => {
    expect(cartCookieOptions("http://localhost:3000")).toEqual({
      httpOnly: true,
      sameSite: "lax",
      path: "/",
      secure: false,
      maxAge: 30 * 24 * 60 * 60,
    });
    expect(CART_COOKIE_MAX_AGE_SECONDS).toBe(2_592_000);
  });

  it("is Secure for a store that is not local", () => {
    expect(cartCookieOptions("https://shop.example.com").secure).toBe(true);
  });
});

describe("readCartId", () => {
  it("returns the id of a cookie signed by the store", () => {
    expect(readCartId(signValue(ID, SECRET), SECRET)).toBe(ID);
  });

  it.each([
    ["undefined", undefined],
    ["unsigned", ID],
    ["signed with another secret", signValue(ID, "x".repeat(64))],
    ["signed but not a UUID", signValue("session-1", SECRET)],
  ])("returns null for a value that is %s", (_name, value) => {
    expect(readCartId(value, SECRET)).toBeNull();
  });
});
