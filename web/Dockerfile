FROM node:20-alpine as builder

WORKDIR /app
# Copy backend views so Tailwind can scan them for classes
COPY backend/app/view ./backend/app/view

WORKDIR /app/web
COPY web/package.json web/package-lock.json ./
RUN npm config set registry https://registry.npmmirror.com
RUN npm ci
COPY web/src ./src
COPY web/tailwind.config.js web/postcss.config.js ./
RUN npm run build

FROM nginx:alpine
COPY web/nginx.conf /etc/nginx/nginx.conf
COPY --from=builder /app/web/dist /usr/share/nginx/html/assets
