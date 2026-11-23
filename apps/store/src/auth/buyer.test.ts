import { createHmac, hkdfSync, timingSafeEqual } from "node:crypto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { buyerTag, GUEST_BUYER, isBuyerOf } from "./buyer";
import { readSignedValue, signValue } from "./cookies";

// The comparison is watched through the real function: it must be the one that decides, for every input.
vi.mock("node:crypto", async (importOriginal) => {
  const actual = await importOriginal<typeof import("node:crypto")>();
  return { ...actual, timingSafeEqual: vi.fn(actual.timingSafeEqual) };
});

const SECRET = "s".repeat(64);
/**
 * The buyer tag as the spec defines it, computed here and not by the code under test: HMAC-SHA256 of the sub with a key
 * of its own, derived from the session secret by HKDF-SHA256 (no salt, info „checkout-buyer”), base64url.
 */
const expected = (sub: string, secret = SECRET) =>
  createHmac("sha256", Buffer.from(hkdfSync("sha256", secret, Buffer.alloc(0), "checkout-buyer", 32))).update(sub).digest("base64url");

beforeEach(() => {
  vi.mocked(timingSafeEqual).mockClear();
});

describe("buyerTag", () => {
  it("is the HMAC-SHA256 of the sub with the key derived from the session secret, in base64url", () => {
    expect(buyerTag("user-1", SECRET)).toBe(expected("user-1"));
    expect(buyerTag("user-1", SECRET)).toMatch(/^[A-Za-z0-9_-]{43}$/);
  });

  it("is the same for the same sub and differs for another sub or another secret", () => {
    expect(buyerTag("user-1", SECRET)).toBe(buyerTag("user-1", SECRET));
    expect(buyerTag("user-2", SECRET)).not.toBe(buyerTag("user-1", SECRET));
    expect(buyerTag("user-1", "x".repeat(64))).not.toBe(buyerTag("user-1", SECRET));
  });

  // The key of the tags is not the key of the signatures of the cookies: no tag is the signature of any value, so a
  // tag never makes a cookie the store would accept, whatever the shape of the cookies to come.
  it.each(["user-1", "0d4b7e52-6c1a-4f0e-9d3b-2a1c5e7f9b10"])("is never the signature of a cookie value built from the sub %s", (sub) => {
    const tag = buyerTag(sub, SECRET);
    for (const value of [`checkout-buyer:${sub}`, sub, `checkout-buyer${sub}`, `checkout-buyer:${sub}:`, "checkout-buyer"]) {
      expect(readSignedValue(`${value}.${tag}`, SECRET)).toBeNull();
      expect(signValue(value, SECRET)).not.toBe(`${value}.${tag}`);
    }
  });

  it("never contains the sub", () => {
    const sub = "0d4b7e52-6c1a-4f0e-9d3b-2a1c5e7f9b10";
    expect(buyerTag(sub, SECRET)).not.toContain(sub);
  });
});

describe("isBuyerOf", () => {
  it("accepts the tag of the sub", () => {
    expect(isBuyerOf(expected("user-1"), "user-1", SECRET)).toBe(true);
  });

  it.each([
    ["the tag of another account", expected("user-2")],
    ["a tag made with another secret", expected("user-1", "x".repeat(64))],
    ["the guest", GUEST_BUYER],
    ["the sub itself", "user-1"],
    ["the tag with another case", expected("user-1").toUpperCase()],
    ["the tag with a character more", `${expected("user-1")}=`],
    ["the tag cut short", expected("user-1").slice(0, -1)],
    ["the tag with a NUL", `${expected("user-1")}\u0000`],
    ["the tag with a line separator", `${expected("user-1")} `],
    ["the tag with blank space", ` ${expected("user-1")} `],
    ["a very long text", "A".repeat(100_000)],
    ["an empty text", ""],
  ])("refuses %s", (_name, shown) => {
    expect(isBuyerOf(shown, "user-1", SECRET)).toBe(false);
  });

  it("compares in constant time, also when the lengths differ", () => {
    for (const shown of [expected("user-1"), expected("user-2"), GUEST_BUYER, "A".repeat(10_000)]) {
      vi.mocked(timingSafeEqual).mockClear();
      isBuyerOf(shown, "user-1", SECRET);
      expect(timingSafeEqual).toHaveBeenCalledTimes(1);
      const [given, wanted] = vi.mocked(timingSafeEqual).mock.calls[0] as [Buffer, Buffer];
      expect(given.length).toBe(wanted.length);
    }
  });
});
