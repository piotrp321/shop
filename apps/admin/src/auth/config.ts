import type { UserManagerSettings } from "oidc-client-ts";
import { readUrl, type ConfigSources } from "@/config";

export interface AuthConfig {
  keycloakUrl: string;
  adminUrl: string;
}

export function readAuthConfig(sources?: ConfigSources): AuthConfig {
  return {
    keycloakUrl: readUrl("VITE_KEYCLOAK_URL", sources),
    adminUrl: readUrl("VITE_ADMIN_URL", sources),
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
