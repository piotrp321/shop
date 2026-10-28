# syntax=docker/dockerfile:1

# Pinned: Node 24.22.1 (npm 11.19.1) on Alpine. The source is bind-mounted in development
# and node_modules lives in a named volume, so the image only provides the runtime.
FROM node:24.22.1-alpine

ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /app
