import { Pressable, Text } from "react-native";
import { router } from "expo-router";

import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { Card, Heading, Screen, styles } from "@/ui/primitives";

export default function OrganizationScreen() {
  const { t } = useMobileI18n();
  const { session, chooseOrganization } = useMobileSession();

  return (
    <Screen>
      <Heading>{t("mobile.organizationSelector")}</Heading>
      {session?.organizations.map((organization) => (
        <Card key={organization.id}>
          <Text>{organization.name}</Text>
          <Text style={styles.muted}>{t(`roles.${organization.role}`)}</Text>
          <Pressable
            onPress={() => {
              chooseOrganization(organization.id);
              router.replace("/location");
            }}
            style={styles.button}
          >
            <Text style={styles.buttonText}>{t("common.select")}</Text>
          </Pressable>
        </Card>
      ))}
    </Screen>
  );
}
