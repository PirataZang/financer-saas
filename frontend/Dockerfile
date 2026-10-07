# Stage 1: Base image
FROM node:20-alpine AS base
WORKDIR /app
COPY package*.json ./
RUN npm install

# Stage 2: Development image
FROM base AS development
COPY . .
EXPOSE 5174
CMD ["npm", "run", "dev"]

# Stage 3: Build image
FROM base AS build
COPY . .
RUN npm run build

# Stage 4: Production image
FROM nginx:alpine AS production
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
