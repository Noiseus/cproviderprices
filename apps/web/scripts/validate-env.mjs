const requiredVariables = ["NEXT_PUBLIC_API_URL"];
const missingVariables = requiredVariables.filter(
  (name) => !process.env[name]?.trim(),
);

if (missingVariables.length > 0) {
  console.error(
    `Missing required web environment values: ${missingVariables.join(", ")}`,
  );
  process.exit(1);
}

console.log("Web environment configuration is valid.");
