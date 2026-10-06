const environment = process.env.WEB_ENV?.trim() || "local";
const requiredVariables = ["API_INTERNAL_URL"];
const missingVariables = requiredVariables.filter(
  (name) => !process.env[name]?.trim(),
);

const validEnvironments = new Set(["local", "staging", "production"]);

if (!validEnvironments.has(environment)) {
  console.error("WEB_ENV must be one of: local, staging, production.");
  process.exit(1);
}

if (missingVariables.length > 0) {
  console.error(
    `Missing required web environment values: ${missingVariables.join(", ")}`,
  );
  process.exit(1);
}

if (["staging", "production"].includes(environment)) {
  const webUrl = process.env.WEB_URL?.trim();
  if (!webUrl?.startsWith("https://")) {
    console.error("WEB_URL must be an HTTPS URL in staging and production.");
    process.exit(1);
  }
}

console.log("Web environment configuration is valid.");
