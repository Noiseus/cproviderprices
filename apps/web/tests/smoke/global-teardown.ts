import { rmSync } from "node:fs";
import { resolve } from "node:path";

export default function globalTeardown() {
  rmSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e-isolated.json"),
    {
      force: true,
    },
  );
}
