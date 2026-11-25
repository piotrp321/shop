# syntax=docker/dockerfile:1

# Pinned: Node 24.22.3 (npm 11.19.1) on Alpine, by the digest of the multi-arch index of the tag
# (`docker buildx imagetools inspect node:24.22.3-alpine`, line "Digest"); change the tag and the digest together.
FROM node:24.22.3-alpine@sha256:116b800c3754b440ee0aa3ed2f20f5bf3d99a554bfd9adeea09ea8017f6e85f5 AS node

ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app

# Production build: dependencies of the lock file and `next build` with output "standalone", switched on by
# STORE_STANDALONE only here (next.config.ts), so `STORE_MODE=production` in Compose builds as before. The build calls
# no service: API_CATALOG_URL is a placeholder that refuses connections at once (port 9 of the loopback), set for
# `next build` only (not an ENV of any stage, so the stage prod has no such variable). Without it the read of the
# category tree would fail before its fetch, and the pages prerendered here (/cart, /konto/zamowienia, the 404) would
# get no `catalog` tag: the failed tagged fetch gives them the tag, so the revalidation of the tag after a deployment
# (the job purge-caches) renders them again with the menu. Until then their header has no menu (see CLAUDE.md).
#
# Source maps (next.config.ts): the deploy workflow builds with SENTRY_UPLOAD=true when it has the Sentry token, which
# it passes as the build secret `sentry_auth_token` (a secret mount: never an argument, never in a layer or the log).
# Without the flag the build needs no secret. The arguments are declared right before the build, so their values are
# part of its cache key: a layer built without an upload is never reused for one. Whatever the flag, no .map file stays
# in the image.
FROM node AS build

# The plugin uploads through the Sentry CLI used as a library, which has telemetry of its own (on by default, not covered by
# `telemetry: false` of the plugin). This variable switches it off, so the build sends nothing but the maps.
ENV SENTRY_CLI_NO_TELEMETRY=1

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
ARG SENTRY_UPLOAD=false
ARG SENTRY_ORG=
ARG SENTRY_PROJECT=
# The store has no public/ directory yet; the runtime stage copies it.
RUN --mount=type=secret,id=sentry_auth_token \
    mkdir -p public \
    && if [ "$SENTRY_UPLOAD" = true ]; then \
      SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token)" && export SENTRY_AUTH_TOKEN || exit 1; \
    fi; \
    API_CATALOG_URL=http://127.0.0.1:9 STORE_STANDALONE=1 npm run build && find .next -name '*.map' -delete

# Production image: the standalone server only (traced dependencies, no npm, no sources), run by the unprivileged user.
# The ISR cache stays in memory (next.config.ts), so nothing grows on the writable layer at run time.
FROM node AS prod

ENV NODE_ENV=production \
    HOSTNAME=0.0.0.0 \
    PORT=3000

COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public

USER node
EXPOSE 3000
CMD ["node", "server.js"]

# Development image (Compose builds the last stage): the source is bind-mounted and node_modules lives in a named
# volume.
FROM node AS dev

# Dependencies of the lock file as of the build. Docker copies them into the node_modules volume when it is created
# empty, so a fresh stack (CI) starts without `npm ci`. The container command still runs `npm ci` when
# package-lock.json is newer than the install, and an existing volume keeps its own content.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
