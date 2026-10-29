/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Browser-facing URL of the API. */
  readonly VITE_API_URL: string;
  /** Browser-facing URL of Keycloak. */
  readonly VITE_KEYCLOAK_URL: string;
  /** Browser-facing URL of this panel. */
  readonly VITE_ADMIN_URL: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
