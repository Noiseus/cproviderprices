import { Pressable, Text } from "react-native";

import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { Card, Heading, Screen, styles } from "@/ui/primitives";

export default function SettingsScreen() {
  const { t, locale } = useMobileI18n();
  const { updateLanguage, signOut } = useMobileSession();
  return (
    <Screen>
      <Heading>{t("settings.title")}</Heading>
      <Card>
        <Text>{t("settings.languageLabel")}</Text>
        {(["en", "es"] as const).map((language) => (
          <Pressable
            key={language}
            onPress={() => void updateLanguage(language)}
            style={styles.button}
          >
            <Text style={styles.buttonText}>
              {language === "en" ? t("nav.english") : t("nav.spanish")}
              {locale === language ? " ✓" : ""}
            </Text>
          </Pressable>
        ))}
      </Card>
      <Pressable onPress={() => void signOut()} style={styles.button}>
        <Text style={styles.buttonText}>{t("auth.signOut")}</Text>
      </Pressable>
    </Screen>
  );
}
