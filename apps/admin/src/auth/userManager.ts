import { InMemoryWebStorage, UserManager, WebStorageStateStore } from "oidc-client-ts";
import { createUserManagerSettings, readAuthConfig, type AuthConfig } from "@/auth/config";

export function createUserManager(config: AuthConfig): UserManager {
  return new UserManager({
    ...createUserManagerSettings(config),
    // Tokens live in memory only: a reload drops them and the guard signs in again through the SSO session.
    userStore: new WebStorageStateStore({ store: new InMemoryWebStorage() }),
    // The state of a sign-in request in flight (state id, PKCE code verifier) must survive the redirect to Keycloak
    // and back, which a reload of the page would wipe from memory. It holds no token and the library removes
    // the entry when the callback is processed. sessionStorage is private to the tab and ends with it.
    stateStore: new WebStorageStateStore({ store: window.sessionStorage }),
  });
}

let instance: UserManager | undefined;

/** The one user manager of the running panel; created on first use so that importing it needs no env. */
export function getUserManager(): UserManager {
  instance ??= createUserManager(readAuthConfig(import.meta.env));
  return instance;
}
