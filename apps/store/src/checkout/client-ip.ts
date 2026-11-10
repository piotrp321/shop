import type { ApiClient } from "@/api/client";

/**
 * The header of the address of the client (`CLIENT_IP_HEADER`, `CF-Connecting-IP` behind Cloudflare), or null when the
 * variable is unset or empty (docs/features/hardening/spec.md, "Adres IP klienta").
 */
export function clientIpHeader(env: Record<string, string | undefined> = process.env): string | null {
  const name = env.CLIENT_IP_HEADER?.trim();
  return name ? name : null;
}

/**
 * The API client with the header of the client address copied from the request of the browser to every call, so
 * that the rate limits of the API count by the customer and not by the store. Nothing is added when the variable is
 * not set or the request has no such header (the API then counts by the address of the store). The value goes as it
 * came: the API takes it only when it is one valid IP address.
 */
export function forwardingClientIp(api: ApiClient, request: Request, env: Record<string, string | undefined> = process.env): ApiClient {
  const name = clientIpHeader(env);
  const value = name === null ? null : request.headers.get(name);
  if (name === null || value === null) {
    return api;
  }
  api.use({
    onRequest({ request: outgoing }) {
      outgoing.headers.set(name, value);
      return outgoing;
    },
  });
  return api;
}
