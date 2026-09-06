FROM node:24.18.0-alpine AS build

WORKDIR /app
ARG VITE_API_BASE_URL=http://localhost:8000/api
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 5173
HEALTHCHECK --interval=10s --timeout=3s --retries=6 \
  CMD wget -q -O - http://127.0.0.1:5173/health || exit 1

CMD ["nginx", "-g", "daemon off;"]
