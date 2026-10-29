import { describe, expect, it } from "vitest";
import { readAuthConfig } from "./config";

const env = {
  STORE_URL: "http://localhost:3000/",
  API_INTERNAL_URL: "http://api",
  REDIS_URL: "redis://redis:6379",
  KEYCLOAK_PUBLIC_URL: "http://localhost:8080/",
  KEYCLOAK_INTERNAL_URL: "http://keycloak:8080",
  STORE_CLIENT_SECRET: "client-secret-value",
  STORE_SESSION_SECRET: "s".repeat(64),
};

describe("readAuthConfig", () => {
  it("reads the configuration and drops trailing slashes", () => {
    expect(readAuthConfig(env)).toEqual({
      storeUrl: "http://localhost:3000",
      apiInternalUrl: "http://api",
      redisUrl: "redis://redis:6379",
      keycloakPublicUrl: "http://localhost:8080",
      keycloakInternalUrl: "http://keycloak:8080",
      clientSecret: "client-secret-value",
      sessionSecret: "s".repeat(64),
    });
  });

  it("names every missing variable without printing any value", () => {
    const incomplete = { ...env, STORE_URL: "", REDIS_URL: undefined, STORE_CLIENT_SECRET: undefined };
    expect(() => readAuthConfig(incomplete)).toThrow(
      "Missing environment variables: STORE_URL, REDIS_URL, STORE_CLIENT_SECRET.",
    );
  });

  it("rejects a short session secret without printing it", () => {
    let message = "";
    try {
      readAuthConfig({ ...env, STORE_SESSION_SECRET: "short-secret" });
    } catch (error) {
      message = (error as Error).message;
    }
    expect(message).toBe("STORE_SESSION_SECRET must be at least 32 characters long.");
  });
});
