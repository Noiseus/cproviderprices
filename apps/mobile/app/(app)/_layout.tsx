import { Tabs } from "expo-router";

import { useMobileI18n } from "@/state/i18n";

export default function BuyerTabsLayout() {
  const { t } = useMobileI18n();
  return (
    <Tabs screenOptions={{ headerShown: true }}>
      <Tabs.Screen name="dashboard" options={{ title: t("nav.dashboard") }} />
      <Tabs.Screen name="search" options={{ title: t("nav.search") }} />
      <Tabs.Screen name="lists" options={{ title: t("nav.lists") }} />
      <Tabs.Screen name="imports" options={{ title: t("nav.imports") }} />
      <Tabs.Screen name="settings" options={{ title: t("nav.settings") }} />
      <Tabs.Screen
        name="compare/[canonicalProductId]"
        options={{ href: null, title: t("comparison.title") }}
      />
      <Tabs.Screen
        name="lists/[listId]"
        options={{ href: null, title: t("lists.title") }}
      />
    </Tabs>
  );
}
