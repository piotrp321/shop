import {
  allowInsecureRequests,
  authorizationCodeGrant,
  buildAuthorizationUrl,
  buildEndSessionUrl,
  calculatePKCECodeChallenge,
  type Configuration,
  customFetch,
  type CustomFetch,
  discovery,
  enableNonRepudiationChecks,
  randomNonce,
  randomPKCECodeVerifier,
  randomState,
  refreshTokenGrant,
  ResponseBodyError,
  type TokenEndpointResponse,
} from "openid-client";
import { type AuthConfig, CLIENT_ID, REALM } from "./config";
import { RefreshRejectedError, type TokenSet } from "./refresh";

export type LoginChecks = { state: string; nonce: string; codeVerifier: string };

export interface OidcClient {
  /** New random state, nonce and PKCE verifier, and the Keycloak URL (public) to send the browser to. */
  startLogin(): Promise<LoginChecks & { url: URL }>;
  /** Checks the callback and the id_token, then exchanges the code for tokens (internal token endpoint). */
  exchangeCode(callbackQuery: URLSearchParams, checks: LoginChecks): Promise<{ sub: string; tokens: TokenSet }>;
  /** Throws {@link RefreshRejectedError} when Keycloak refuses the refresh token. */
  refresh(refreshToken: string): Promise<TokenSet>;
  /** RP-initiated logout at Keycloak's (public) end_session endpoint. */
  endSessionUrl(idToken: string): URL;
}

// Seconds; keeps a refresh well inside the 5 s refresh lock.
const HTTP_TIMEOUT_SECONDS = 4;

function toTokenSet(response: TokenEndpointResponse): TokenSet {
  if (!response.refresh_token || typeof response.expires_in !== "number") {
    throw new Error("The token response has no refresh token or no expiry.");
  }
  const refreshExpiresIn = response.refresh_expires_in;
  return {
    accessToken: response.access_token,
    refreshToken: response.refresh_token,
    idToken: response.id_token,
    expiresIn: response.expires_in,
    refreshExpiresIn: typeof refreshExpiresIn === "number" && refreshExpiresIn > 0 ? refreshExpiresIn : undefined,
  };
}

export function createOidcClient(configuration: Configuration, config: AuthConfig): OidcClient {
  const redirectUri = `${config.storeUrl}/auth/callback`;

  return {
    async startLogin() {
      const state = randomState();
      const nonce = randomNonce();
      const codeVerifier = randomPKCECodeVerifier();
      const url = buildAuthorizationUrl(configuration, {
        redirect_uri: redirectUri,
        scope: "openid",
        code_challenge: await calculatePKCECodeChallenge(codeVerifier),
        code_challenge_method: "S256",
        state,
        nonce,
      });
      return { url, state, nonce, codeVerifier };
    },

    async exchangeCode(callbackQuery, checks) {
      // Built from STORE_URL, never from the request's Host header: openid-client derives redirect_uri from it.
      const currentUrl = new URL(redirectUri);
      currentUrl.search = callbackQuery.toString();
      const response = await authorizationCodeGrant(configuration, currentUrl, {
        expectedState: checks.state,
        expectedNonce: checks.nonce,
        pkceCodeVerifier: checks.codeVerifier,
        idTokenExpected: true,
      });
      const sub = response.claims()?.sub;
      if (!sub) {
        throw new Error("The token response has no id_token subject.");
      }
      return { sub, tokens: toTokenSet(response) };
    },

    async refresh(refreshToken) {
      try {
        return toTokenSet(await refreshTokenGrant(configuration, refreshToken));
      } catch (error) {
        if (error instanceof ResponseBodyError && error.error === "invalid_grant") {
          throw new RefreshRejectedError();
        }
        throw error;
      }
    },

    endSessionUrl(idToken) {
      return buildEndSessionUrl(configuration, {
        id_token_hint: idToken,
        post_logout_redirect_uri: `${config.storeUrl}/`,
      });
    },
  };
}

/**
 * Discovers the `shop` realm over the internal URL. Keycloak answers there with the public issuer and public
 * browser endpoints, but internal token and JWKS endpoints. The issuer is therefore checked against the public
 * URL here instead of against the discovery URL. id_token signatures are verified with the realm's keys.
 */
export async function discoverOidc(config: AuthConfig, options: { fetch?: CustomFetch } = {}): Promise<Configuration> {
  const discoveryUrl = new URL(`${config.keycloakInternalUrl}/realms/${REALM}/.well-known/openid-configuration`);
  const execute = [enableNonRepudiationChecks];
  // Local development runs Keycloak over plain HTTP; with HTTPS URLs openid-client refuses HTTP altogether.
  if ([config.keycloakInternalUrl, config.keycloakPublicUrl].some((url) => new URL(url).protocol === "http:")) {
    execute.push(allowInsecureRequests);
  }
  const configuration = await discovery(discoveryUrl, CLIENT_ID, config.clientSecret, undefined, {
    execute,
    timeout: HTTP_TIMEOUT_SECONDS,
    ...(options.fetch ? { [customFetch]: options.fetch } : {}),
  });

  const expectedIssuer = `${config.keycloakPublicUrl}/realms/${REALM}`;
  if (configuration.serverMetadata().issuer !== expectedIssuer) {
    throw new Error(`Keycloak reports the issuer ${configuration.serverMetadata().issuer}, expected ${expectedIssuer}.`);
  }
  return configuration;
}
