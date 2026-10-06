import { Stack } from "expo-router";
import { StatusBar } from "expo-status-bar";

import { MobileI18nProvider } from "@/state/i18n";
import {
  MobileSessionProvider,
  useMobileSession,
} from "@/state/mobile-session";

function MobileAppNavigator() {
  const { session } = useMobileSession();

  return (
    <MobileI18nProvider locale={session?.user.preferred_language ?? "en"}>
      <StatusBar style="dark" />
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="index" />
        <Stack.Screen name="sign-in" />
        <Stack.Screen name="organization" options={{ headerShown: true }} />
        <Stack.Screen name="location" options={{ headerShown: true }} />
        <Stack.Screen name="(app)" />
      </Stack>
    </MobileI18nProvider>
  );
}

export default function RootLayout() {
  return (
    <MobileSessionProvider>
      <MobileAppNavigator />
    </MobileSessionProvider>
  );
}
