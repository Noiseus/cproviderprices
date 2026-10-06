import { useState } from "react";
import { Pressable, StyleSheet, Text, TextInput } from "react-native";
import { router } from "expo-router";

import { localizedMobileError } from "@/lib/errors";
import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { Card, ErrorState, Heading, Screen, styles } from "@/ui/primitives";
import { theme, tokens } from "@/theme/tokens";

export default function SignInScreen() {
  const { t, locale } = useMobileI18n();
  const { signIn } = useMobileSession();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await signIn(email, password);
      router.replace("/");
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Screen>
      <Heading>{t("auth.signIn")}</Heading>
      <Card>
        <Text style={styles.muted}>{t("mobile.signInHelp")}</Text>
        <Text>{t("auth.email")}</Text>
        <TextInput
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          onChangeText={setEmail}
          style={local.input}
          value={email}
        />
        <Text>{t("auth.password")}</Text>
        <TextInput
          autoComplete="current-password"
          onChangeText={setPassword}
          secureTextEntry
          style={local.input}
          value={password}
        />
        <Pressable
          accessibilityRole="button"
          disabled={submitting}
          onPress={() => void submit()}
          style={[styles.button, submitting ? local.disabled : null]}
        >
          <Text style={styles.buttonText}>
            {submitting ? t("common.working") : t("auth.signIn")}
          </Text>
        </Pressable>
      </Card>
      {error === null ? null : <ErrorState message={error} />}
    </Screen>
  );
}

const local = StyleSheet.create({
  input: {
    borderColor: theme.border,
    borderRadius: tokens.radius.sm,
    borderWidth: 1,
    minHeight: tokens.touchTarget.minimum,
    paddingHorizontal: tokens.spacing[3],
  },
  disabled: { opacity: 0.65 },
});
