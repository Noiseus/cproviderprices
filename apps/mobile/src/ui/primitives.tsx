import type { PropsWithChildren } from "react";
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { theme, tokens } from "@/theme/tokens";
import { useMobileI18n } from "@/state/i18n";

export function Screen({ children }: PropsWithChildren) {
  return (
    <SafeAreaView edges={["left", "right", "bottom"]} style={styles.safeArea}>
      <ScrollView contentContainerStyle={styles.content}>{children}</ScrollView>
    </SafeAreaView>
  );
}

export function Heading({ children }: PropsWithChildren) {
  return <Text style={styles.heading}>{children}</Text>;
}

export function Card({ children }: PropsWithChildren) {
  return <View style={styles.card}>{children}</View>;
}

export function LoadingState() {
  const { t } = useMobileI18n();
  return (
    <View accessibilityRole="progressbar" style={styles.centered}>
      <ActivityIndicator color={theme.primary} />
      <Text>{t("common.loading")}</Text>
    </View>
  );
}

export function ErrorState({
  message,
  onRetry,
}: {
  message: string;
  onRetry?: () => void;
}) {
  const { t } = useMobileI18n();
  return (
    <Card>
      <Text accessibilityRole="alert" style={styles.error}>
        {message}
      </Text>
      {onRetry === undefined ? null : (
        <Pressable onPress={onRetry} style={styles.button}>
          <Text style={styles.buttonText}>{t("common.retry")}</Text>
        </Pressable>
      )}
    </Card>
  );
}

export const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: theme.background },
  content: {
    gap: tokens.spacing[4],
    padding: tokens.spacing[4],
    paddingBottom: tokens.spacing[10],
  },
  heading: {
    color: theme.text,
    fontSize: tokens.typography.fontSize.xl,
    fontWeight: "700",
  },
  card: {
    gap: tokens.spacing[2],
    backgroundColor: theme.surface,
    borderColor: theme.border,
    borderRadius: tokens.radius.md,
    borderWidth: 1,
    padding: tokens.spacing[4],
  },
  centered: {
    alignItems: "center",
    gap: tokens.spacing[2],
    justifyContent: "center",
    minHeight: 160,
  },
  error: { color: theme.danger, fontWeight: "600" },
  button: {
    alignItems: "center",
    backgroundColor: theme.primary,
    borderRadius: tokens.radius.sm,
    minHeight: tokens.touchTarget.minimum,
    justifyContent: "center",
    paddingHorizontal: tokens.spacing[4],
  },
  buttonText: { color: theme.surface, fontWeight: "700" },
  muted: { color: theme.mutedText },
});
