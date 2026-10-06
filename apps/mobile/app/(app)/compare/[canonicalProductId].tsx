import { useCallback, useEffect, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";

import { localizedMobileError } from "@/lib/errors";
import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { winnerLabelKeys } from "@/view-models/buyer";
import {
  Card,
  ErrorState,
  Heading,
  LoadingState,
  Screen,
  styles,
} from "@/ui/primitives";
import type {
  PriceHistoryResponse,
  ProductComparisonResponse,
} from "@/types/api";

export default function ComparisonScreen() {
  const { canonicalProductId } = useLocalSearchParams<{
    canonicalProductId: string;
  }>();
  const { t, locale } = useMobileI18n();
  const { api, activeOrganization, activeLocation } = useMobileSession();
  const [result, setResult] = useState<ProductComparisonResponse | null>(null);
  const [history, setHistory] = useState<PriceHistoryResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (
      activeOrganization === null ||
      activeLocation === null ||
      canonicalProductId === undefined
    ) {
      return;
    }
    setError(null);
    try {
      const [comparison, priceHistory] = await Promise.all([
        api.comparison(
          activeOrganization.id,
          canonicalProductId,
          activeLocation.id,
        ),
        api.priceHistory(
          activeOrganization.id,
          canonicalProductId,
          activeLocation.id,
        ),
      ]);
      setResult(comparison);
      setHistory(priceHistory);
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    }
  }, [activeLocation, activeOrganization, api, canonicalProductId, locale]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <Screen>
      <Heading>
        {result?.canonical_product.english_name ?? t("comparison.title")}
      </Heading>
      {error === null ? null : (
        <ErrorState message={error} onRetry={() => void load()} />
      )}
      {result === null && error === null ? <LoadingState /> : null}
      {result?.warnings.map((warning) => (
        <Card key={warning}>
          <Text style={styles.error}>{warning}</Text>
        </Card>
      ))}
      {history === null ? null : (
        <Card>
          <Text>{t("history.title")}</Text>
          <Text style={styles.muted}>
            {t("mobile.historySummary", {
              observations: history.observations.length,
              points: history.series.length,
            })}
          </Text>
        </Card>
      )}
      {result?.offers.map((offer) => (
        <Card key={offer.id}>
          <Text>{offer.supplier.display_name}</Text>
          <Text>
            {t("comparison.casePrice")}: {offer.case_price} {offer.currency}
          </Text>
          <Text>
            {t("comparison.normalizedPrice")}:{" "}
            {offer.normalized_price ?? t("common.notAvailable")}{" "}
            {offer.normalized_unit ?? ""}
          </Text>
          <Text style={styles.muted}>
            {t(`freshness.${offer.freshness}`)} ·{" "}
            {t(`match.${offer.comparison_class}`)}
          </Text>
          {winnerLabelKeys(offer).map((key) => (
            <Text key={key}>{t(key)}</Text>
          ))}
          {offer.warnings.map((warning) => (
            <Text key={warning} style={styles.error}>
              {warning}
            </Text>
          ))}
        </Card>
      ))}
    </Screen>
  );
}
