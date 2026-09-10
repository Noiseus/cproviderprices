import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/smoke",
  timeout: 15_000,
  fullyParallel: true,
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
