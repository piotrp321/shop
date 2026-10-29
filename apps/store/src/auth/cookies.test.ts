import { describe, expect, it } from "vitest";
import { cookieOptions, LOGIN_COOKIE, loginCookieOptions, readSignedValue, SESSION_COOKIE, signValue } from "./cookies";

const SECRET = "a".repeat(64);

describe("signed cookie values", () => {
  it("reads back a value it signed", () => {
    expect(readSignedValue(signValue("abc_DEF-123", SECRET), SECRET)).toBe("abc_DEF-123");
  });

  it("puts the value and a signature in the cookie", () => {
    const signed = signValue("abc", SECRET);
    expect(signed.startsWith("abc.")).toBe(true);
    expect(signed.length).toBeGreaterThan("abc.".length + 40);
  });

  it.each([
    ["missing", undefined],
    ["empty", ""],
    ["unsigned", "abc"],
    ["empty signature", "abc."],
    ["changed value", signValue("abc", SECRET).replace(/^abc/, "abd")],
    ["changed signature", signValue("abc", SECRET).slice(0, -2) + "AA"],
    ["truncated signature", signValue("abc", SECRET).slice(0, -1)],
    ["extra part", `${signValue("abc", SECRET)}.x`],
  ])("rejects a %s value", (_name, value) => {
    expect(readSignedValue(value, SECRET)).toBeNull();
  });

  it("rejects a value signed with another secret", () => {
    expect(readSignedValue(signValue("abc", "b".repeat(64)), SECRET)).toBeNull();
  });
});

describe("cookie options", () => {
  it("names the cookies", () => {
    expect(SESSION_COOKIE).toBe("store_session");
    expect(LOGIN_COOKIE).toBe("store_login");
  });

  it.each([["http://localhost:3000"], ["http://127.0.0.1:3000"], ["http://[::1]:3000"]])("omits Secure on %s", (storeUrl) => {
    expect(cookieOptions(storeUrl)).toEqual({ httpOnly: true, sameSite: "lax", path: "/", secure: false });
  });

  it.each([["https://sklep.example.com"], ["http://sklep.example.com"], ["https://localhost.evil.com"]])(
    "sets Secure on %s",
    (storeUrl) => {
      expect(cookieOptions(storeUrl).secure).toBe(true);
    },
  );

  it("limits the login cookie to the callback and ten minutes", () => {
    expect(loginCookieOptions("http://localhost:3000")).toEqual({
      httpOnly: true,
      sameSite: "lax",
      path: "/auth/callback",
      secure: false,
      maxAge: 600,
    });
  });
});
