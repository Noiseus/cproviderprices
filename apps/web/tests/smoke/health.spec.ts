import { expect, test } from "@playwright/test";

const checks = [
  {
    name: "web health",
    url: process.env.WEB_HEALTH_URL ?? "http://localhost:3000/api/health",
    expectedStatus: "ok",
  },
  {
    name: "API health",
    url: process.env.API_HEALTH_URL ?? "http://localhost:8080/api/v1/health",
    expectedStatus: "ok",
  },
  {
    name: "API readiness",
    url:
      process.env.API_READINESS_URL ?? "http://localhost:8080/api/v1/readiness",
    expectedStatus: "ready",
  },
  {
    name: "ingestion health",
    url: process.env.INGESTION_HEALTH_URL ?? "http://localhost:8001/health",
    expectedStatus: "ok",
  },
] as const;

for (const check of checks) {
  test(check.name, async ({ request }) => {
    const response = await request.get(check.url);

    expect(response.ok()).toBe(true);
    await expect(response.json()).resolves.toMatchObject({
      status: check.expectedStatus,
    });
  });
}

test("Mailpit UI", async ({ request }) => {
  const response = await request.get(
    process.env.MAILPIT_URL ?? "http://localhost:8025/",
  );

  expect(response.ok()).toBe(true);
});
