import { generateKeyPairSync, type KeyObject, sign } from "node:crypto";
import { customFetch } from "openid-client";
import { beforeEach, describe, expect, it } from "vitest";
import { createOidcClient, discoverOidc } from "./oidc";
import { RefreshRejectedError } from "./refresh";
import { testConfig, testOidcConfiguration } from "./test-support";

const realm = `${testConfig.keycloakPublicUrl}/realms/shop`;
const base64url = (value: object) => Buffer.from(JSON.stringify(value)).toString("base64url");

// Signature checks are enabled only for the discovered configuration (enableNonRepudiationChecks), so a placeholder
// signature is enough to exercise the claim checks here.
function idToken(claims: Record<string, unknown>) {
  const now = Math.floor(Date.now() / 1000);
  const payload = { iss: realm, aud: "store", sub: "user-7", iat: now, exp: now + 300, ...claims };
  return `${base64url({ alg: "RS256", typ: "JWT" })}.${base64url(payload)}.c2lnbmF0dXJl`;
}

type Call = { url: string; body: URLSearchParams };
let calls: Call[];
let reply: () => Response;

const json = (body: object, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

function client() {
  const configuration = testOidcConfiguration();
  configuration[customFetch] = async (url, options) => {
    calls.push({ url: String(url), body: new URLSearchParams(String(options.body)) });
    return reply();
  };
  return createOidcClient(configuration, testConfig);
}

const checks = { state: "state-1", nonce: "nonce-1", codeVerifier: "v".repeat(43) };

beforeEach(() => {
  calls = [];
  reply = () =>
    json({
      access_token: "access-1",
      refresh_token: "refresh-1",
      id_token: idToken({ nonce: "nonce-1" }),
      token_type: "Bearer",
      expires_in: 300,
      refresh_expires_in: 1800,
    });
});

describe("OIDC client", () => {
  it("exchanges the code at the internal token endpoint with the verifier and the store's redirect URI", async () => {
    const result = await client().exchangeCode(new URLSearchParams("code=abc&state=state-1"), checks);

    expect(result).toEqual({
      sub: "user-7",
      tokens: { accessToken: "access-1", refreshToken: "refresh-1", idToken: expect.any(String), expiresIn: 300, refreshExpiresIn: 1800 },
    });
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toBe(`${testConfig.keycloakInternalUrl}/realms/shop/protocol/openid-connect/token`);
    expect(calls[0].body.get("grant_type")).toBe("authorization_code");
    expect(calls[0].body.get("code")).toBe("abc");
    expect(calls[0].body.get("code_verifier")).toBe(checks.codeVerifier);
    expect(calls[0].body.get("redirect_uri")).toBe(`${testConfig.storeUrl}/auth/callback`);
    expect(calls[0].body.get("client_id")).toBe("store");
    expect(calls[0].body.get("client_secret")).toBe(testConfig.clientSecret);
  });

  it("rejects a mismatched state before calling Keycloak", async () => {
    await expect(client().exchangeCode(new URLSearchParams("code=abc&state=other"), checks)).rejects.toThrow();
    expect(calls).toHaveLength(0);
  });

  it.each([
    ["another nonce", { nonce: "other" }],
    ["no nonce", { nonce: undefined }],
    ["another issuer", { nonce: "nonce-1", iss: "http://keycloak:8080/realms/shop" }],
    ["another audience", { nonce: "nonce-1", aud: "admin" }],
    ["an expired token", { nonce: "nonce-1", exp: Math.floor(Date.now() / 1000) - 600 }],
  ])("rejects an id_token with %s", async (_name, claims) => {
    reply = () =>
      json({ access_token: "a", refresh_token: "r", id_token: idToken(claims), token_type: "Bearer", expires_in: 300 });
    await expect(client().exchangeCode(new URLSearchParams("code=abc&state=state-1"), checks)).rejects.toThrow();
  });

  it("requires an id_token", async () => {
    reply = () => json({ access_token: "a", refresh_token: "r", token_type: "Bearer", expires_in: 300 });
    await expect(client().exchangeCode(new URLSearchParams("code=abc&state=state-1"), checks)).rejects.toThrow();
  });

  it("refreshes tokens", async () => {
    reply = () =>
      json({ access_token: "access-2", refresh_token: "refresh-2", token_type: "Bearer", expires_in: 300, refresh_expires_in: 1700 });

    expect(await client().refresh("refresh-1")).toEqual({
      accessToken: "access-2",
      refreshToken: "refresh-2",
      idToken: undefined,
      expiresIn: 300,
      refreshExpiresIn: 1700,
    });
    expect(calls[0].body.get("grant_type")).toBe("refresh_token");
    expect(calls[0].body.get("refresh_token")).toBe("refresh-1");
  });

  it("turns invalid_grant into RefreshRejectedError", async () => {
    reply = () => json({ error: "invalid_grant", error_description: "Session not active" }, 400);
    await expect(client().refresh("refresh-1")).rejects.toBeInstanceOf(RefreshRejectedError);
  });

  it("passes other refresh failures through", async () => {
    reply = () => json({ error: "temporarily_unavailable" }, 503);
    const error = await client().refresh("refresh-1").catch((e: unknown) => e);

    expect(error).toBeInstanceOf(Error);
    expect(error).not.toBeInstanceOf(RefreshRejectedError);
  });

  it("builds the RP-initiated logout URL at the public end_session endpoint", () => {
    const url = client().endSessionUrl("id-token-7");

    expect(`${url.origin}${url.pathname}`).toBe(`${realm}/protocol/openid-connect/logout`);
    expect(url.searchParams.get("id_token_hint")).toBe("id-token-7");
    expect(url.searchParams.get("post_logout_redirect_uri")).toBe(`${testConfig.storeUrl}/`);
  });
});

describe("discoverOidc", () => {
  const metadata = (issuer: string) => ({
    issuer,
    authorization_endpoint: `${realm}/protocol/openid-connect/auth`,
    token_endpoint: `${testConfig.keycloakInternalUrl}/realms/shop/protocol/openid-connect/token`,
    jwks_uri: `${testConfig.keycloakInternalUrl}/realms/shop/protocol/openid-connect/certs`,
    end_session_endpoint: `${realm}/protocol/openid-connect/logout`,
  });

  it("asks the internal URL and accepts the public issuer", async () => {
    const requested: string[] = [];
    const configuration = await discoverOidc(testConfig, {
      fetch: async (url) => {
        requested.push(String(url));
        return json(metadata(realm));
      },
    });

    expect(requested).toEqual([`${testConfig.keycloakInternalUrl}/realms/shop/.well-known/openid-configuration`]);
    expect(configuration.serverMetadata().issuer).toBe(realm);
    expect(configuration.clientMetadata().client_id).toBe("store");
  });

  it.each([
    ["the internal URL", `${testConfig.keycloakInternalUrl}/realms/shop`],
    ["another realm", `${testConfig.keycloakPublicUrl}/realms/backoffice`],
    ["another host", "http://evil.com/realms/shop"],
  ])("rejects metadata whose issuer is %s", async (_name, issuer) => {
    await expect(discoverOidc(testConfig, { fetch: async () => json(metadata(issuer)) })).rejects.toThrow(
      /expected http:\/\/localhost:8080\/realms\/shop/,
    );
  });
});

describe("id_token signature check of the discovered configuration", () => {
  const signingKey = generateKeyPairSync("rsa", { modulusLength: 2048 });
  const otherKey = generateKeyPairSync("rsa", { modulusLength: 2048 });
  const jwk = { ...signingKey.publicKey.export({ format: "jwk" }), kid: "key-1", alg: "RS256", use: "sig" };

  const signedIdToken = (privateKey: KeyObject, claims: Record<string, unknown> = {}) => {
    const now = Math.floor(Date.now() / 1000);
    const header = base64url({ alg: "RS256", typ: "JWT", kid: "key-1" });
    const payload = base64url({ iss: realm, aud: "store", sub: "user-7", iat: now, exp: now + 300, nonce: "nonce-1", ...claims });
    const signature = sign("sha256", Buffer.from(`${header}.${payload}`), privateKey).toString("base64url");
    return `${header}.${payload}.${signature}`;
  };

  async function exchangeWith(idToken: string) {
    const internal = `${testConfig.keycloakInternalUrl}/realms/shop`;
    const configuration = await discoverOidc(testConfig, {
      fetch: async (url) => {
        const href = String(url);
        if (href.endsWith("/.well-known/openid-configuration")) {
          return json({
            issuer: realm,
            authorization_endpoint: `${realm}/protocol/openid-connect/auth`,
            token_endpoint: `${internal}/protocol/openid-connect/token`,
            jwks_uri: `${internal}/protocol/openid-connect/certs`,
            end_session_endpoint: `${realm}/protocol/openid-connect/logout`,
          });
        }
        if (href.endsWith("/certs")) {
          return json({ keys: [jwk] });
        }
        return json({
          access_token: "access-1",
          refresh_token: "refresh-1",
          id_token: idToken,
          token_type: "Bearer",
          expires_in: 300,
        });
      },
    });
    return createOidcClient(configuration, testConfig).exchangeCode(new URLSearchParams("code=abc&state=state-1"), checks);
  }

  it("accepts an id_token signed with the realm's key", async () => {
    expect((await exchangeWith(signedIdToken(signingKey.privateKey))).sub).toBe("user-7");
  });

  it("rejects an id_token signed with another key", async () => {
    await expect(exchangeWith(signedIdToken(otherKey.privateKey))).rejects.toThrow();
  });

  it("rejects an id_token whose payload was changed after signing", async () => {
    const [header, , signature] = signedIdToken(signingKey.privateKey).split(".");
    const forged = base64url({ iss: realm, aud: "store", sub: "admin", iat: 0, exp: 9_999_999_999, nonce: "nonce-1" });
    await expect(exchangeWith(`${header}.${forged}.${signature}`)).rejects.toThrow();
  });
});
