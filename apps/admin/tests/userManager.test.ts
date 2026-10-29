import { User } from "oidc-client-ts";
import { beforeEach, describe, expect, it } from "vitest";
import { readAuthConfig } from "@/auth/config";
import { createUserManager } from "@/auth/userManager";

const config = { keycloakUrl: "http://localhost:46080", adminUrl: "http://localhost:46173" };

describe("readAuthConfig", () => {
  it("reads the Keycloak and admin URLs", () => {
    expect(
      readAuthConfig({ VITE_KEYCLOAK_URL: "http://localhost:46080/", VITE_ADMIN_URL: "http://localhost:46173/" }),
    ).toEqual(config);
  });

  it.each([
    [{ VITE_ADMIN_URL: "http://localhost:46173" }, "VITE_KEYCLOAK_URL"],
    [{ VITE_KEYCLOAK_URL: "http://localhost:46080" }, "VITE_ADMIN_URL"],
    [{ VITE_KEYCLOAK_URL: "", VITE_ADMIN_URL: "http://localhost:46173" }, "VITE_KEYCLOAK_URL"],
  ])("throws naming the missing variable %#", (env, name) => {
    expect(() => readAuthConfig(env)).toThrow(name);
  });
});

describe("createUserManager", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  it("uses the backoffice realm, the public admin client, the code flow and silent renew", () => {
    const { settings } = createUserManager(config);

    expect(settings.authority).toBe("http://localhost:46080/realms/backoffice");
    expect(settings.client_id).toBe("admin");
    expect(settings.client_secret).toBeUndefined();
    expect(settings.response_type).toBe("code");
    expect(settings.redirect_uri).toBe("http://localhost:46173/auth/callback");
    expect(settings.post_logout_redirect_uri).toBe("http://localhost:46173/");
    expect(settings.automaticSilentRenew).toBe(true);
  });

  it("keeps PKCE enabled (the library sends S256 with the code flow)", () => {
    expect(createUserManager(config).settings.disablePKCE).toBeFalsy();
  });

  it("keeps the user and the tokens in memory, never in web storage or cookies", async () => {
    const manager = createUserManager(config);
    const user = new User({
      access_token: "access-secret",
      refresh_token: "refresh-secret",
      id_token: "id-secret",
      token_type: "Bearer",
      profile: { sub: "u1", iss: "x", aud: "admin", exp: 1, iat: 1 },
    });

    await manager.storeUser(user);

    expect((await manager.getUser())?.access_token).toBe("access-secret");
    const dump = JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }) + document.cookie;
    expect(dump).not.toContain("secret");
  });

  it("does not leave the user behind in a new manager (reload loses the memory)", async () => {
    const manager = createUserManager(config);
    await manager.storeUser(
      new User({ access_token: "a", token_type: "Bearer", profile: { sub: "u1", iss: "x", aud: "admin", exp: 1, iat: 1 } }),
    );

    expect(await createUserManager(config).getUser()).toBeNull();
  });
});
