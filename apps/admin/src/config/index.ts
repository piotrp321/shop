/** Keys of the runtime configuration: the names of the Vite variables, also used in window.__ADMIN_CONFIG__. */
export type ConfigKey = "VITE_API_URL" | "VITE_KEYCLOAK_URL" | "VITE_ADMIN_URL" | "VITE_STORE_URL";

export interface ConfigSources {
  /** window.__ADMIN_CONFIG__: written at container start by the production image (/config.js). */
  runtime: Partial<Record<string, string>> | undefined;
  /** import.meta.env: the Vite variables of the dev server (compose) or of the build. */
  env: Partial<Record<string, string>>;
}

function currentSources(): ConfigSources {
  return { runtime: window.__ADMIN_CONFIG__, env: import.meta.env };
}

/**
 * Reads a required, browser-facing URL: window.__ADMIN_CONFIG__ first, import.meta.env when the key is not set there.
 * Drops trailing slashes. A key missing in both sources is an error naming the key.
 */
export function readUrl(name: ConfigKey, sources: ConfigSources = currentSources()): string {
  const value = sources.runtime?.[name] || sources.env[name];
  if (!value) {
    throw new Error(`${name} is not set: neither window.__ADMIN_CONFIG__ (/config.js) nor the Vite environment has it.`);
  }
  return value.replace(/\/+$/, "");
}
