import { defineConfig, globalIgnores } from "eslint/config";
import nextPlugin from "@next/eslint-plugin-next";
import { fileURLToPath } from "node:url";
import tseslint from "typescript-eslint";

const webRoot = fileURLToPath(new URL("./apps/web/", import.meta.url));

export default defineConfig([
  globalIgnores([
    "**/.next/**",
    "**/.expo/**",
    "**/.expo-export-check/**",
    "**/node_modules/**",
    "**/next-env.d.ts",
    "packages/generated-api-client/src/schema.d.ts",
  ]),
  ...tseslint.configs.strict,
  {
    files: ["apps/web/**/*.{ts,tsx}"],
    plugins: {
      "@next/next": nextPlugin,
    },
    settings: {
      next: {
        rootDir: webRoot,
      },
    },
    rules: {
      ...nextPlugin.configs.recommended.rules,
      ...nextPlugin.configs["core-web-vitals"].rules,
    },
  },
]);
