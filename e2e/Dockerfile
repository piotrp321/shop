# The Playwright image must match the exact @playwright/test version in package.json (it carries the browsers): change
# the tag, the digest and the version in package.json together, by hand (Dependabot does not update this file).
# Pinned: Playwright 1.64.1 on Ubuntu noble, by the digest of the multi-arch index of the tag
# (`docker buildx imagetools inspect mcr.microsoft.com/playwright:v1.64.1-noble`, line "Digest").
FROM mcr.microsoft.com/playwright:v1.64.1-noble@sha256:2f05f8a734509a7b3f26a912639488ee08f58a7b292d14d62271bcff9038768f

# socat forwards localhost:<public port> to the services, so the test browser uses the same addresses
# as a browser on the host (matching OIDC `iss` and redirect URIs).
RUN apt-get update \
    && apt-get install -y --no-install-recommends socat \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Tests, support code and configuration are mounted by Compose, so editing them needs no rebuild.
ENTRYPOINT ["entrypoint.sh"]
