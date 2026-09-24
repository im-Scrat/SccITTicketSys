# syntax=docker/dockerfile:1

# =============================================================
#  Node image for the SccIT frontend.
#  dev  -> Vite dev server with HMR (source bind-mounted)
#  prod -> static build served by Nginx (multi-stage seam)
# =============================================================

# ---------- Development: Vite dev server + browser harness ---
FROM node:22-alpine AS development
WORKDIR /app

# Chromium for the Playwright E2E and axe accessibility suites (WP-2.7d).
#
# It is baked here rather than installed into the running container, which is
# how the browser arrived for the Phase 2.4–2.6b verification passes. That
# worked exactly once: the browser lived in a container's writable layer, so it
# vanished on the next `docker compose up --build` and no one could reproduce a
# result without repeating an undocumented `apk add`. Baking it makes the
# harness reproducible, which is the whole point of committing it.
#
# Alpine's own Chromium is used instead of Playwright's download because this
# is an Alpine (musl) image and Playwright ships glibc builds. The nss / freetype
# / harfbuzz / font packages are Chromium's runtime dependencies — without them
# it starts and then crashes on the first page paint, which reads as a mysterious
# test timeout rather than a missing library.
RUN apk add --no-cache \
        chromium \
        nss \
        freetype \
        harfbuzz \
        ca-certificates \
        ttf-freefont

# Consumed by playwright.config.ts. PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD must be set
# BEFORE npm ci, or installing @playwright/test pulls ~400 MB of glibc browsers
# that could never run in this image.
ENV CHROMIUM_PATH=/usr/bin/chromium \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

# Install from the lockfile into the image; at runtime a named volume
# mounts over /app/node_modules and is seeded from this layer, giving
# the container Linux-native binaries (not the Windows host's).
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]

# ---------- Build: compile static assets ---------------------
FROM node:22-alpine AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---------- Production: serve static via Nginx --------------
FROM nginx:1.27-alpine AS production
COPY --from=build /app/dist /usr/share/nginx/html
