import type { Instrumentation } from "next";
import { readSentryConfig } from "@/observability/config";

/**
 * Sentry on the server of the store (spec: docs/features/sentry/spec.md, D1, D2, D8): only in the Node.js runtime and
 * only with `SENTRY_DSN`, read when the server starts. Without a DSN the module of the SDK is not loaded at all.
 */

// `process.env.NEXT_RUNTIME` is a constant in the build of each runtime, so the Edge build drops the import of the SDK
// of Node.js.
export async function register(): Promise<void> {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    const config = readSentryConfig(process.env);
    if (config !== null) {
      const { initServerSentry } = await import("@/observability/server");
      initServerSentry(config, process.env);
    }
  }
}

export const onRequestError: Instrumentation.onRequestError = async (error, request, context) => {
  if (process.env.NEXT_RUNTIME === "nodejs" && readSentryConfig(process.env) !== null) {
    const { captureServerRequestError } = await import("@/observability/server");
    captureServerRequestError(error, request, context);
  }
};
