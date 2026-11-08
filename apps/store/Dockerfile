# syntax=docker/dockerfile:1

# Pinned: Node 24.22.1 (npm 11.19.1) on Alpine.
FROM node:24.22.1-alpine AS node

ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app

# Production build: dependencies of the lock file and `next build` with output "standalone", switched on by
# STORE_STANDALONE only here (next.config.ts), so `STORE_MODE=production` in Compose builds as before. The build needs
# no secrets and calls no service: it prerenders without API_CATALOG_URL and its pages fall back (see CLAUDE.md).
FROM node AS build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# The store has no public/ directory yet; the runtime stage copies it.
RUN mkdir -p public \
    && STORE_STANDALONE=1 npm run build

# Production image: the standalone server only (traced dependencies, no npm, no sources). `.next/cache` (ISR) is
# written at run time, so `.next` belongs to the unprivileged user.
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
