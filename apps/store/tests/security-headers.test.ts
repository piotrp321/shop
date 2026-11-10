import { describe, expect, it } from "vitest";
import nextConfig from "../next.config";

async function responseHeaders(path: string): Promise<Map<string, string>> {
  const rules = (await nextConfig.headers?.()) ?? [];
  const headers = new Map<string, string>();
  for (const rule of rules) {
    // A route pattern with a catch-all parameter at the root matches every path, `/` included.
    if (rule.source === "/:path*" || rule.source === path) {
      for (const { key, value } of rule.headers) headers.set(key, value);
    }
  }
  return headers;
}

// Spec: docs/features/hardening/spec.md (D10). The store sets no `script-src`: a nonce would make every page dynamic
// and switch ISR off.
describe("security headers of the store", () => {
  it.each(["/", "/p/some-product", "/api/checkout", "/_next/static/chunk.js"])("are set on %s", async (path) => {
    const headers = await responseHeaders(path);
    expect(headers.get("X-Content-Type-Options")).toBe("nosniff");
    expect(headers.get("Referrer-Policy")).toBe("strict-origin-when-cross-origin");
    expect(headers.get("X-Frame-Options")).toBe("DENY");
    expect(headers.get("Permissions-Policy")).toBe("camera=(), microphone=(), geolocation=()");
    expect(headers.get("Content-Security-Policy")).toBe("frame-ancestors 'none'; object-src 'none'; base-uri 'self'");
  });

  it("do not restrict scripts and do not touch the cache headers", async () => {
    const headers = await responseHeaders("/");
    expect(headers.get("Content-Security-Policy") ?? "").not.toContain("script-src");
    expect(headers.get("Content-Security-Policy") ?? "").not.toContain("default-src");
    expect([...headers.keys()].filter((key) => /^(cache-control|surrogate-key|cache-tag|vary)$/i.test(key))).toEqual([]);
  });
});
