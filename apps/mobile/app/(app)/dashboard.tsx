import { Pressable, Text } from "react-native";
import { router } from "expo-router";

import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { Card, Heading, Screen, styles } from "@/ui/primitives";

export default function DashboardScreen() {
  const { t } = useMobileI18n();
  const { activeOrganization, activeLocation } = useMobileSession();
  return (
    <Screen>
      <Heading>{t("dashboard.overview")}</Heading>
      <Card>
        <Text>{activeOrganization?.name}</Text>
        <Text style={styles.muted}>
          {activeLocation?.name} · {activeLocation?.timezone}
        </Text>
        <Pressable
          onPress={() => router.push("/organization")}
          style={styles.button}
        >
          <Text style={styles.buttonText}>{t("nav.organization")}</Text>
        </Pressable>
      </Card>
      <Card>
        <Text>{t("mobile.dashboardReadiness")}</Text>
      </Card>
    </Screen>
  );
}
