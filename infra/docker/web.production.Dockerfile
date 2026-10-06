FROM node:24.8.0-bookworm-slim AS build

WORKDIR /workspace

COPY package.json package-lock.json ./
COPY apps/web/package.json apps/web/package.json
COPY packages/api-contracts/package.json packages/api-contracts/package.json
COPY packages/generated-api-client/package.json packages/generated-api-client/package.json
COPY packages/design-tokens/package.json packages/design-tokens/package.json
COPY packages/domain-types/package.json packages/domain-types/package.json
COPY packages/i18n/package.json packages/i18n/package.json

RUN npm ci

COPY tsconfig.base.json ./
COPY apps/web ./apps/web
COPY packages ./packages

RUN npm run build --workspace @cproviderprices/web

FROM node:24.8.0-bookworm-slim AS runtime

ENV NODE_ENV=production \
    HOSTNAME=0.0.0.0 \
    PORT=3000

RUN groupadd --system --gid 1001 nextjs \
    && useradd --system --uid 1001 --gid nextjs --home-dir /app nextjs

WORKDIR /app

COPY --from=build --chown=nextjs:nextjs /workspace/apps/web/.next/standalone ./
COPY --from=build --chown=nextjs:nextjs /workspace/apps/web/.next/static ./apps/web/.next/static
COPY --from=build --chown=nextjs:nextjs /workspace/apps/web/public ./apps/web/public
COPY --from=build --chown=nextjs:nextjs /workspace/apps/web/scripts ./apps/web/scripts
COPY infra/docker/web-production-entrypoint.sh ./entrypoint.sh

RUN chmod 0555 ./entrypoint.sh

USER nextjs

EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
  CMD node -e "fetch('http://localhost:3000/api/health').then((response) => process.exit(response.ok ? 0 : 1)).catch(() => process.exit(1))"

CMD ["./entrypoint.sh"]
