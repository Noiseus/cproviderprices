const checks = [
  {
    name: "web health",
    url: process.env.WEB_HEALTH_URL ?? "http://localhost:3000/api/health",
    expectedStatus: "ok",
  },
  {
    name: "API health",
    url:
      process.env.API_HEALTH_URL ??
      "http://localhost:8080/api/v1/health",
    expectedStatus: "ok",
  },
  {
    name: "API readiness",
    url:
      process.env.API_READINESS_URL ??
      "http://localhost:8080/api/v1/readiness",
    expectedStatus: "ready",
  },
  {
    name: "ingestion health",
    url:
      process.env.INGESTION_HEALTH_URL ??
      "http://localhost:8001/health",
    expectedStatus: "ok",
  },
];

let failed = false;

for (const check of checks) {
  try {
    const response = await fetch(check.url);
    const body = await response.json();
    if (!response.ok || body.status !== check.expectedStatus) {
      throw new Error(`HTTP ${response.status}, status=${body.status}`);
    }
    console.log(`PASS ${check.name}`);
  } catch (error) {
    failed = true;
    console.error(`FAIL ${check.name}: ${error.message}`);
  }
}

try {
  const response = await fetch(
    process.env.MAILPIT_URL ?? "http://localhost:8025/",
  );
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  console.log("PASS Mailpit UI");
} catch (error) {
  failed = true;
  console.error(`FAIL Mailpit UI: ${error.message}`);
}

if (failed) {
  process.exit(1);
}
