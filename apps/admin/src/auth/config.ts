import type { UserManagerSettings } from "oidc-client-ts";

export interface AuthConfig {
  keycloakUrl: string;
  adminUrl: string;
}

/** Reads a required, browser-facing URL from the Vite env and drops a trailing slash. */
export function requiredUrl(env: Partial<Record<string, string>>, name: string): string {
  const value = env[name];
  if (!value) {
    throw new Error(`${name} is not set. Compose passes it to the admin container.`);
  }
  return value.replace(/\/+$/, "");
}

export function readAuthConfig(env: Partial<Record<string, string>>): AuthConfig {
  return {
    keycloakUrl: requiredUrl(env, "VITE_KEYCLOAK_URL"),
    adminUrl: requiredUrl(env, "VITE_ADMIN_URL"),
  };
}

/**
 * Authorization Code flow with PKCE (S256, the library default for the code flow) as the public client `admin`
 * of the `backoffice` realm. The access token is renewed with the refresh token before it expires.
 */
export function createUserManagerSettings(
  config: AuthConfig,
): Pick<
  UserManagerSettings,
  "authority" | "client_id" | "redirect_uri" | "post_logout_redirect_uri" | "response_type" | "scope" | "automaticSilentRenew"
> {
  return {
    authority: `${config.keycloakUrl}/realms/backoffice`,
    client_id: "admin",
    redirect_uri: `${config.adminUrl}/auth/callback`,
    post_logout_redirect_uri: `${config.adminUrl}/`,
    response_type: "code",
    scope: "openid",
    automaticSilentRenew: true,
  };
}
