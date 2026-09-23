import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/smoke",
  timeout: process.env.PLAYWRIGHT_DOCKER ? 30_000 : 15_000,
  fullyParallel: true,
  workers: process.env.PLAYWRIGHT_DOCKER ? 1 : undefined,
  globalTeardown: "./tests/smoke/global-teardown.ts",
  reporter: "line",
  use: {
    trace: "retain-on-failure",
    launchOptions: process.env.PLAYWRIGHT_DOCKER
      ? {
          args: ["--host-resolver-rules=MAP localhost host.docker.internal"],
        }
      : undefined,
  },
});
