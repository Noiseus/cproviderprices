import { Redirect } from "expo-router";

import { LoadingState, Screen } from "@/ui/primitives";
import { useMobileSession } from "@/state/mobile-session";

export default function IndexScreen() {
  const { booting, session, activeOrganization, activeLocation } =
    useMobileSession();

  if (booting) {
    return (
      <Screen>
        <LoadingState />
      </Screen>
    );
  }
  if (session === null) {
    return <Redirect href="/sign-in" />;
  }
  if (activeOrganization === null) {
    return <Redirect href="/organization" />;
  }
  if (activeLocation === null) {
    return <Redirect href="/location" />;
  }

  return <Redirect href="/(app)/dashboard" />;
}
