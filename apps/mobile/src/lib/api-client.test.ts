import { describe, expect, it } from "vitest";

import { createMobileApiClient } from "@/lib/api-client";
import {
  createMobileAuthStorage,
  type SecureValueStore,
} from "@/lib/auth-storage";
import { MobileApiError } from "@/lib/errors";

function authStorage() {
  const values = new Map<string, string>();
  const store: SecureValueStore = {
    getItem: async (key) => values.get(key) ?? null,
    setItem: async (key, value) => {
      values.set(key, value);
    },
    removeItem: async (key) => {
      values.delete(key);
    },
  };
  return createMobileAuthStorage(store);
}

describe("mobile API client", () => {
  it("uses generated API paths, bearer auth, and correlation IDs", async () => {
    const storage = authStorage();
    await storage.write({
      accessToken: "cpm1.example",
      expiresAt: "2026-12-01T00:00:00Z",
    });
    const requests: Request[] = [];
    const client = createMobileApiClient({
      baseUrl: "https://api.example.test/api/v1",
      authStorage: storage,
      requestId: () => "mobile-test-1",
      fetchImpl: async (input, init) => {
        requests.push(new Request(input, init));
        return Response.json({
          results: [],
          pagination: {},
          partial: { supplier_accounts: [] },
        });
      },
    });

    await client.search("organization-1", {
      q: "tomato",
      locationId: "location-1",
      sort: "relevance",
      freshness: ["fresh"],
    });
    expect(requests[0]?.url).toContain(
      "/organizations/organization-1/product-search?q=tomato&location_id=location-1&sort=relevance",
    );
    expect(requests[0]?.headers.get("Authorization")).toBe(
      "Bearer cpm1.example",
    );
    expect(requests[0]?.headers.get("X-Request-ID")).toBe("mobile-test-1");
    expect(requests[0]?.url).toContain("freshness%5B%5D=fresh");
  });

  it("clears an expired device token from secure storage", async () => {
    const storage = authStorage();
    await storage.write({
      accessToken: "cpm1.expired",
      expiresAt: "2026-12-01T00:00:00Z",
    });
    const client = createMobileApiClient({
      baseUrl: "https://api.example.test/api/v1",
      authStorage: storage,
      fetchImpl: async () =>
        Response.json(
          { error: { code: "mobile_token_expired", request_id: "trace-1" } },
          { status: 401 },
        ),
    });

    await expect(client.session()).rejects.toEqual(expect.any(MobileApiError));
    await expect(storage.read()).resolves.toBeNull();
  });
});
