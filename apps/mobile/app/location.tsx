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
import type { RestaurantLocationListResponse } from "@/types/api";

export default function LocationScreen() {
  const { t, locale } = useMobileI18n();
  const { api, activeOrganization, chooseLocation } = useMobileSession();
  const [result, setResult] = useState<RestaurantLocationListResponse | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (activeOrganization === null) {
      return;
    }
    setError(null);
    try {
      setResult(await api.locations(activeOrganization.id));
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    }
  }, [activeOrganization, api, locale]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <Screen>
      <Heading>{t("mobile.locationSelector")}</Heading>
      {error === null ? null : (
        <ErrorState message={error} onRetry={() => void load()} />
      )}
      {result === null && error === null ? <LoadingState /> : null}
      {result?.restaurant_locations
        .filter((location) => location.status === "active")
        .map((location) => (
          <Card key={location.id}>
            <Text>{location.name}</Text>
            <Text style={styles.muted}>{location.timezone}</Text>
            <Pressable
              onPress={() => {
                chooseLocation(location);
                router.replace("/");
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
