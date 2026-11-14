import { sentryVitePlugin } from "@sentry/vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";
import { defineConfig, loadEnv, type PluginOption } from "vite";

/**
 * Source maps of the production build (spec: docs/features/sentry/spec.md, P3, D10). The deploy workflow builds the image
 * with SENTRY_UPLOAD=true only when the secret SENTRY_AUTH_TOKEN exists (the Dockerfile reads it from a build secret).
 * Then the build writes hidden maps (no sourceMappingURL comment in the bundle), the plugin uploads them matched by debug
 * ID and deletes them, and a failed upload stops the build. Without the flag no map is written and the plugin is not
 * loaded, so a build without the secret (a pull request, the smoke test, `make up`) makes no request to Sentry.
 */
export function sentrySourceMaps(env: Record<string, string | undefined>): { plugins: PluginOption[]; sourcemap: false | "hidden" } {
  const flag = env.SENTRY_UPLOAD ?? "";
  if (flag !== "" && flag !== "true" && flag !== "false") {
    throw new Error(`SENTRY_UPLOAD must be "true" or "false" (got "${flag}").`);
  }
  if (flag !== "true") {
    return { plugins: [], sourcemap: false };
  }
  for (const key of ["SENTRY_AUTH_TOKEN", "SENTRY_ORG", "SENTRY_PROJECT"]) {
    if (!env[key]) {
      throw new Error(`SENTRY_UPLOAD is true, so ${key} is required (source maps are not built silently without an upload).`);
    }
  }
  return {
    sourcemap: "hidden",
    plugins: [
      sentryVitePlugin({
        authToken: env.SENTRY_AUTH_TOKEN,
        org: env.SENTRY_ORG,
        project: env.SENTRY_PROJECT,
        // No data about the build goes to Sentry (the plugin sends its own errors and timings by default).
        telemetry: false,
        // The release comes from the runtime configuration; maps are matched by debug ID, so no release is created.
        release: { inject: false, create: false, finalize: false, setCommits: false, deploy: false },
        sourcemaps: { deleteSourcemapsAfterUpload: true, filesToDeleteAfterUpload: ["./dist/**/*.map"] },
        // The plugin's own default is also to throw; explicit, because a skipped upload must never pass silently.
        errorHandler: (error) => {
          throw error;
        },
      }),
    ],
  };
}

export default defineConfig(({ mode }) => {
  const sourceMaps = sentrySourceMaps(loadEnv(mode, ".", "SENTRY_"));
  return {
    // The Sentry plugin comes last: it reads the bundles after the other plugins have written them.
    plugins: [vue(), tailwindcss(), ...sourceMaps.plugins],
    build: { sourcemap: sourceMaps.sourcemap },
    resolve: {
      alias: { "@": new URL("./src", import.meta.url).pathname },
    },
    server: {
      // Inside Docker the dev server must listen on all interfaces. localhost (any port) is allowed by default.
      host: "0.0.0.0",
      port: 5173,
      strictPort: true,
    },
  };
});
