# syntax=docker/dockerfile:1

# Pinned: Node 24.22.1 (npm 11.19.1) on Alpine. The source is bind-mounted in development
# and node_modules lives in a named volume.
FROM node:24.22.1-alpine

ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app

# Dependencies of the lock file as of the build. Docker copies them into the node_modules volume when it is created
# empty, so a fresh stack (CI) starts without `npm ci`. The container command still runs `npm ci` when
# package-lock.json is newer than the install, and an existing volume keeps its own content.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
