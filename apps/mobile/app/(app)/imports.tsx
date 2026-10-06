import { Text } from "react-native";

import { useMobileI18n } from "@/state/i18n";
import { Card, Heading, Screen, styles } from "@/ui/primitives";

export default function ImportsScreen() {
  const { t } = useMobileI18n();
  return (
    <Screen>
      <Heading>{t("imports.title")}</Heading>
      <Card>
        <Text>{t("mobile.importsDeferred")}</Text>
        <Text style={styles.muted}>{t("mobile.importsDeferredDetail")}</Text>
      </Card>
    </Screen>
  );
}
