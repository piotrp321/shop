import { createApiClient } from "@/api/client";

export type MeResult = { kind: "ok"; name: string } | { kind: "unauthorized" } | { kind: "unavailable" };

/**
 * `GET /api/v1/me` with the session's access token. Only 401 means the token is bad; any other failure
 * (503 when the API cannot fetch Keycloak's keys) says nothing about the session.
 */
export async function fetchMe(apiInternalUrl: string, accessToken: string): Promise<MeResult> {
  const { data, response } = await createApiClient({ baseUrl: apiInternalUrl, accessToken }).GET("/api/v1/me");
  if (data) {
    return { kind: "ok", name: data.name || data.email };
  }
  return response.status === 401 ? { kind: "unauthorized" } : { kind: "unavailable" };
}
