import { describe, expect, it } from "vitest";
import { sanitizeReturnTo } from "./return-to";

const STORE_URL = "http://localhost:3000";

describe("sanitizeReturnTo", () => {
  it.each([["/"], ["/?category=x&page=2"], ["/konto/zamowienia"], ["/a?b=c#d"]])("keeps the store path %s", (value) => {
    expect(sanitizeReturnTo(value, STORE_URL)).toBe(value);
  });

  it.each([
    ["protocol-relative URL", "//evil.com"],
    ["backslash after the slash", "/\\evil.com"],
    ["absolute URL", "https://evil.com"],
    ["absolute URL of the store", "http://localhost:3000/"],
    ["javascript URL", "javascript:alert(1)"],
    ["newline", "/a\nb"],
    ["carriage return", "/a\rb"],
    ["tab", "/\t/evil.com"],
    ["NUL", "/a\u0000b"],
    ["DEL", "/a\u007fb"],
    ["C1 control NEL", "/a\u0085b"],
    ["C1 control CSI", "/a\u009bb"],
    ["backslash later in the path", "/a\\b"],
    ["relative path", "konto"],
    ["encoded slashes without the leading slash", "%2F%2Fevil.com"],
    ["triple slash", "///evil.com"],
    ["empty value", ""],
  ])("rejects %s", (_name, value) => {
    expect(sanitizeReturnTo(value, STORE_URL)).toBe("/");
  });

  it.each([[undefined], [null]])("turns %s into /", (value) => {
    expect(sanitizeReturnTo(value, STORE_URL)).toBe("/");
  });

  // Encoded separators stay encoded: the browser resolves them as a path segment on the store, never as a host.
  it.each([["/%2F%2Fevil.com"], ["/%5Cevil.com"], ["/%2f%2fevil.com"]])("keeps the encoded %s on the store origin", (value) => {
    const result = sanitizeReturnTo(value, STORE_URL);
    expect(new URL(result, STORE_URL).origin).toBe(STORE_URL);
    expect(result.startsWith("//")).toBe(false);
    expect(result).not.toContain("\\");
  });

  // A fullwidth solidus is not a separator: it is percent-encoded into a path segment on the store.
  it("keeps a fullwidth solidus on the store origin, encoded", () => {
    const result = sanitizeReturnTo("/\uff0fevil.com", STORE_URL);
    expect(result).toBe("/%EF%BC%8Fevil.com");
    expect(new URL(result, STORE_URL).origin).toBe(STORE_URL);
  });

  it("normalises dot segments within the store", () => {
    expect(sanitizeReturnTo("/a/../b", STORE_URL)).toBe("/b");
  });
});
