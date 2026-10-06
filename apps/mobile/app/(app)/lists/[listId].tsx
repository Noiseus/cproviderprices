import { useCallback, useEffect, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";

import { localizedMobileError } from "@/lib/errors";
import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { savedListWarningKey, winnerLabelKeys } from "@/view-models/buyer";
import {
  Card,
  ErrorState,
  Heading,
  LoadingState,
  Screen,
  styles,
} from "@/ui/primitives";
import type { SavedListDetail } from "@/types/api";

export default function SavedListDetailScreen() {
  const { listId } = useLocalSearchParams<{ listId: string }>();
  const { t, locale } = useMobileI18n();
  const { api, activeOrganization } = useMobileSession();
  const [list, setList] = useState<SavedListDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (activeOrganization === null || listId === undefined) {
      return;
    }
    setError(null);
    try {
      setList(await api.savedList(activeOrganization.id, listId));
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    }
  }, [activeOrganization, api, listId, locale]);

  useEffect(() => {
    void load();
  }, [load]);
  return (
    <Screen>
      <Heading>{list?.name ?? t("lists.title")}</Heading>
      {error === null ? null : (
        <ErrorState message={error} onRetry={() => void load()} />
      )}
      {list === null && error === null ? <LoadingState /> : null}
      {list?.items.map((item) => {
        const warning = savedListWarningKey(item);
        return (
          <Card key={item.id}>
            <Text>{item.canonical_product.english_name}</Text>
            <Text>
              {t("lists.quantity")}: {item.quantity_target}{" "}
              {item.preferred_unit ?? ""}
            </Text>
            <Text>
              {t("lists.selectedOption")}:{" "}
              {item.selected_offer?.supplier.display_name ?? t("common.none")}
            </Text>
            <Text>
              {t("lists.bestNormalizedOption")}:{" "}
              {item.best_normalized_option?.supplier.display_name ??
                t("common.notAvailable")}
            </Text>
            <Text>
              {t("lists.bestAvailableOption")}:{" "}
              {item.best_available_option?.supplier.display_name ??
                t("common.notAvailable")}
            </Text>
            {warning === null ? null : (
              <Text style={styles.error}>{t(warning)}</Text>
            )}
            {item.best_normalized_option === null
              ? null
              : winnerLabelKeys(item.best_normalized_option).map((key) => (
                  <Text key={key} style={styles.muted}>
                    {t(key)}
                  </Text>
                ))}
            <Text style={styles.muted}>{t("mobile.listEditingDeferred")}</Text>
          </Card>
        );
      })}
    </Screen>
  );
}
