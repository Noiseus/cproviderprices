import { describe, expect, it } from "vitest";

import { createHealthResponse } from "./route";

describe("web health response", () => {
  it("returns a UTC ISO 8601 timestamp", () => {
    const now = new Date("2026-01-02T03:04:05.000Z");

    expect(createHealthResponse(now)).toEqual({
      status: "ok",
      service: "web",
      timestamp: "2026-01-02T03:04:05.000Z",
    });
  });
});
