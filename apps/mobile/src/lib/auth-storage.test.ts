import { describe, expect, it } from "vitest";

import {
  createMobileAuthStorage,
  type SecureValueStore,
} from "@/lib/auth-storage";

function fakeStore(): SecureValueStore & { values: Map<string, string> } {
  const values = new Map<string, string>();
  return {
    values,
    getItem: async (key) => values.get(key) ?? null,
    setItem: async (key, value) => {
      values.set(key, value);
    },
    removeItem: async (key) => {
      values.delete(key);
    },
  };
}

describe("mobile auth storage", () => {
  it("persists token material only through the injected secure store", async () => {
    const store = fakeStore();
    const storage = createMobileAuthStorage(store);

    await storage.write({
      accessToken: "cpm1.test",
      expiresAt: "2026-11-01T00:00:00Z",
    });
    await expect(storage.read()).resolves.toEqual({
      accessToken: "cpm1.test",
      expiresAt: "2026-11-01T00:00:00Z",
    });
    await storage.clear();
    await expect(storage.read()).resolves.toBeNull();
  });

  it("removes corrupt stored values instead of treating them as an authenticated session", async () => {
    const store = fakeStore();
    store.values.set("cproviderprices.mobile.auth.v1", "not-json");

    await expect(createMobileAuthStorage(store).read()).resolves.toBeNull();
    expect(store.values.size).toBe(0);
  });
});
