export interface SecureValueStore {
  getItem(key: string): Promise<string | null>;
  setItem(key: string, value: string): Promise<void>;
  removeItem(key: string): Promise<void>;
}

export interface MobileAuthRecord {
  accessToken: string;
  expiresAt: string;
}

export interface MobileAuthStorage {
  read(): Promise<MobileAuthRecord | null>;
  write(record: MobileAuthRecord): Promise<void>;
  clear(): Promise<void>;
}

const key = "cproviderprices.mobile.auth.v1";

export function createMobileAuthStorage(
  store: SecureValueStore,
): MobileAuthStorage {
  return {
    async read(): Promise<MobileAuthRecord | null> {
      const raw = await store.getItem(key);

      if (raw === null) {
        return null;
      }

      try {
        const parsed: unknown = JSON.parse(raw);
        if (
          typeof parsed === "object" &&
          parsed !== null &&
          "accessToken" in parsed &&
          "expiresAt" in parsed &&
          typeof parsed.accessToken === "string" &&
          typeof parsed.expiresAt === "string"
        ) {
          return {
            accessToken: parsed.accessToken,
            expiresAt: parsed.expiresAt,
          };
        }
      } catch {
        // A corrupt local value is not an authenticated session.
      }

      await store.removeItem(key);
      return null;
    },
    async write(record: MobileAuthRecord): Promise<void> {
      await store.setItem(key, JSON.stringify(record));
    },
    async clear(): Promise<void> {
      await store.removeItem(key);
    },
  };
}
