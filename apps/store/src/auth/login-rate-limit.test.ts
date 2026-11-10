import { describe, expect, it } from "vitest";
import { loginRateLimitKey } from "./login-rate-limit";

const env = { CLIENT_IP_HEADER: "CF-Connecting-IP" };
const key = (value: string | null, vars: Record<string, string | undefined> = env) =>
  loginRateLimitKey(new Headers(value === null ? {} : { "CF-Connecting-IP": value }), vars);

describe("loginRateLimitKey", () => {
  it("is the IPv4 address from the configured header", () => {
    expect(key("203.0.113.7")).toBe("203.0.113.7");
  });

  it("ignores optional whitespace around the value", () => {
    expect(key("  203.0.113.7\t")).toBe("203.0.113.7");
  });

  it("reads the header whose name is configured, in any letter case", () => {
    expect(loginRateLimitKey(new Headers({ "X-Real-IP": "203.0.113.7" }), { CLIENT_IP_HEADER: "x-real-ip" })).toBe("203.0.113.7");
  });

  it("is the /64 prefix of an IPv6 address, however it is written", () => {
    const prefix = key("2001:db8:1:2::1");
    expect(key("2001:DB8:1:2:ffff:ffff:ffff:ffff")).toBe(prefix);
    expect(key("2001:0db8:0001:0002:0000:0000:0000:0001")).toBe(prefix);
    expect(key("2001:db8:1:3::1")).not.toBe(prefix);
    expect(key("2001:db8:1:2::1")).not.toBe("2001:db8:1:2::1");
  });

  it("counts an IPv4-mapped IPv6 address as its IPv4 address", () => {
    expect(key("::ffff:203.0.113.7")).toBe("203.0.113.7");
    expect(key("::ffff:cb00:7107")).toBe("203.0.113.7");
  });

  it("expands :: at the start, the middle and the end", () => {
    expect(key("::1")).toBe(key("0:0:0:0:ffff::9"));
    expect(key("1::")).toBe(key("1:0:0:0:5::"));
    expect(key("1:2::4:5:6:7")).toBe(key("1:2:0:0:1:1:1:1"));
  });

  const shared = key(null, {});

  it("is one shared key without CLIENT_IP_HEADER, with an empty one or without the header in the request", () => {
    expect(key("203.0.113.7", {})).toBe(shared);
    expect(key("203.0.113.7", { CLIENT_IP_HEADER: "  " })).toBe(shared);
    expect(key(null)).toBe(shared);
  });

  it.each([
    [""],
    ["not-an-ip"],
    ["203.0.113.7, 198.51.100.1"],
    ["203.0.113.7:443"],
    ["999.1.1.1"],
    ["fe80::1%eth0"],
    ["[2001:db8::1]"],
    ["203.0.113.7\u00a0"],
  ])("is the shared key for the value %j", (value) => {
    expect(key(value)).toBe(shared);
  });

  it("does not collide the shared key with an address", () => {
    expect(shared).not.toMatch(/^[0-9a-f:.]+$/i);
  });
});
