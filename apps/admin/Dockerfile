# syntax=docker/dockerfile:1

# Pinned: Node 24.22.1 (npm 11.19.1) on Alpine.
FROM node:24.22.1-alpine AS node

WORKDIR /app

# Production build: `vue-tsc` and `vite build`. The URLs are not built in: the panel reads them at run time from
# /config.js (window.__ADMIN_CONFIG__), so one image serves every environment.
FROM node AS build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# Production image: nginx (unprivileged, port 8080) serving dist. /config.js is generated at container start from
# VITE_API_URL, VITE_KEYCLOAK_URL and VITE_ADMIN_URL; a missing variable stops the start with its name.
FROM nginxinc/nginx-unprivileged:1.30.6-alpine AS prod

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
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
