# Build: compile TypeScript.
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

# Runtime: production deps + compiled JS only, unprivileged user. No ports: long polling.
FROM node:22-alpine
ENV NODE_ENV=production
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=build /app/dist ./dist
USER node
# IPv4 only: the container has no IPv6 and Node's dual-stack race gives each attempt 250 ms, less than
# the TCP handshake to api.telegram.org takes here → intermittent ETIMEDOUT on every Telegram call.
CMD ["node", "--dns-result-order=ipv4first", "--no-network-family-autoselection", "dist/index.js"]
