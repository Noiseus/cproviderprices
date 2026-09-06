import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../../", import.meta.url));
const contract = join(root, "packages/api-contracts/openapi.yaml");
const trackedClient = join(root, "packages/generated-api-client/src/schema.d.ts");
const cli = join(dirname(fileURLToPath(import.meta.resolve("openapi-typescript"))), "../bin/cli.js");
const temporaryDirectory = mkdtempSync(join(tmpdir(), "cproviderprices-api-client-"));
const generatedClient = join(temporaryDirectory, "schema.d.ts");

try {
  const generation = spawnSync(process.execPath, [cli, contract, "-o", generatedClient], {
    cwd: root,
    encoding: "utf8",
  });

  if (generation.status !== 0) {
    process.stderr.write(generation.stderr || generation.stdout);
    process.exitCode = generation.status ?? 1;
  } else {
    const normalize = (value) => value.replaceAll("\r\n", "\n");
    const tracked = normalize(readFileSync(trackedClient, "utf8"));
    const generated = normalize(readFileSync(generatedClient, "utf8"));

    if (tracked !== generated) {
      console.error("Generated API client is stale. Run `npm run generate:api` and commit it.");
      process.exitCode = 1;
    } else {
      console.log("Generated API client matches the OpenAPI contract.");
    }
  }
} finally {
  rmSync(temporaryDirectory, { recursive: true, force: true });
}
