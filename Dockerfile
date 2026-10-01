# syntax=docker/dockerfile:1
ARG NODE_VERSION=24

# ---- build: compile the SPA and bundle the server --------------------------------------------
FROM node:${NODE_VERSION}-slim AS build
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
RUN pnpm build

# ---- runtime: production dependencies + the immutable build output -----------------------------
FROM node:${NODE_VERSION}-slim AS runtime
ENV NODE_ENV=production
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
# The server bundle keeps packages external (express, react, react-dom, zod, lucide-react), so they must be installed.
RUN pnpm install --frozen-lockfile --prod && pnpm store prune
COPY --from=build /app/dist ./dist
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
# Configuration comes from the environment (see .env.example / DEPLOYMENT.md); there is no .env in the image.
CMD ["node", "dist/index.js"]
