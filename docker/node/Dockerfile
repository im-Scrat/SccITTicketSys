# syntax=docker/dockerfile:1

# =============================================================
#  Node image for the SccIT frontend.
#  dev  -> Vite dev server with HMR (source bind-mounted)
#  prod -> static build served by Nginx (multi-stage seam)
# =============================================================

# ---------- Development: Vite dev server ---------------------
FROM node:22-alpine AS development
WORKDIR /app
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
