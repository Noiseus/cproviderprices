FROM node:24.8.0-bookworm-slim

WORKDIR /workspace

COPY package.json package-lock.json ./
COPY apps/web/package.json apps/web/package.json
COPY packages/api-contracts/package.json packages/api-contracts/package.json
COPY packages/generated-api-client/package.json packages/generated-api-client/package.json
COPY packages/design-tokens/package.json packages/design-tokens/package.json
COPY packages/domain-types/package.json packages/domain-types/package.json
COPY packages/i18n/package.json packages/i18n/package.json
RUN npm ci

COPY . .

EXPOSE 3000

CMD ["sh", "-c", "npm run validate-env --workspace @cproviderprices/web && npm run dev --workspace @cproviderprices/web -- --hostname 0.0.0.0"]
