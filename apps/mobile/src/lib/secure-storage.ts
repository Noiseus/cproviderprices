import * as SecureStore from "expo-secure-store";

import {
  createMobileAuthStorage,
  type SecureValueStore,
} from "@/lib/auth-storage";

const nativeSecureStore: SecureValueStore = {
  getItem: (key) => SecureStore.getItemAsync(key),
  setItem: (key, value) =>
    SecureStore.setItemAsync(key, value, {
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    }),
  removeItem: (key) => SecureStore.deleteItemAsync(key),
};

export const mobileAuthStorage = createMobileAuthStorage(nativeSecureStore);
