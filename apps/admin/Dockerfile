# syntax=docker/dockerfile:1

# Pinned: Node 24.22.1 (npm 11.19.1) on Alpine.
FROM node:24.22.1-alpine AS node

WORKDIR /app

# Production build: `vue-tsc` and `vite build`. The URLs are not built in: the panel reads them at run time from
# /config.js (window.__ADMIN_CONFIG__), so one image serves every environment.
#
# Source maps (vite.config.ts): the deploy workflow builds with SENTRY_UPLOAD=true when it has the Sentry token, which
# it passes as the build secret `sentry_auth_token` (a secret mount: never an argument, never in a layer or the log).
# The arguments are declared right before the build, so their values are part of its cache key: a layer built without
# an upload is never reused for one. Whatever the flag, no .map file stays in the image.
FROM node AS build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
ARG SENTRY_UPLOAD=false
ARG SENTRY_ORG=
ARG SENTRY_PROJECT=
RUN --mount=type=secret,id=sentry_auth_token \
    if [ "$SENTRY_UPLOAD" = true ]; then \
      SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token)" && export SENTRY_AUTH_TOKEN || exit 1; \
    fi; \
    npm run build && find dist -name '*.map' -delete

# Production image: nginx (unprivileged, port 8080) serving dist. /config.js is generated at container start from
# VITE_API_URL, VITE_KEYCLOAK_URL, VITE_ADMIN_URL and VITE_STORE_URL; a missing variable stops the start with its name.
FROM nginxinc/nginx-unprivileged:1.30.6-alpine AS prod

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/security-headers.conf /etc/nginx/security-headers.conf
COPY --chmod=755 docker/40-admin-config.sh /docker-entrypoint.d/40-admin-config.sh
COPY --from=build /app/dist /usr/share/nginx/html

# Development image (Compose builds the last stage): the source is bind-mounted and node_modules lives in a named
# volume.
FROM node AS dev

# Dependencies of the lock file as of the build. Docker copies them into the node_modules volume when it is created
# empty, so a fresh stack (CI) starts without `npm ci`. The container command still runs `npm ci` when
# package-lock.json is newer than the install, and an existing volume keeps its own content.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
