import { useCallback, useEffect, useState } from "react";
import { Pressable, Text } from "react-native";
import { router } from "expo-router";

import { localizedMobileError } from "@/lib/errors";
import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import {
  Card,
  ErrorState,
  Heading,
  LoadingState,
  Screen,
  styles,
} from "@/ui/primitives";
import type { SavedListIndexResponse } from "@/types/api";

export default function SavedListsScreen() {
  const { t, locale } = useMobileI18n();
  const { api, activeOrganization, activeLocation } = useMobileSession();
  const [result, setResult] = useState<SavedListIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (activeOrganization === null || activeLocation === null) {
      return;
    }
    setError(null);
    try {
      setResult(await api.savedLists(activeOrganization.id, activeLocation.id));
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    }
  }, [activeLocation, activeOrganization, api, locale]);

  useEffect(() => {
    void load();
  }, [load]);
  return (
    <Screen>
      <Heading>{t("lists.title")}</Heading>
      {error === null ? null : (
        <ErrorState message={error} onRetry={() => void load()} />
      )}
      {result === null && error === null ? <LoadingState /> : null}
      {result?.saved_lists.map((list) => (
        <Card key={list.id}>
          <Text>{list.name}</Text>
          <Text style={styles.muted}>
            {list.item_count} {t("mobile.items")}
          </Text>
          <Pressable
            onPress={() => router.push(`/(app)/lists/${list.id}`)}
            style={styles.button}
          >
            <Text style={styles.buttonText}>{t("common.select")}</Text>
          </Pressable>
        </Card>
      ))}
    </Screen>
  );
}
