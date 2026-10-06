import { useState } from "react";
import { Pressable, StyleSheet, Text, TextInput } from "react-native";
import { router } from "expo-router";

import { localizedMobileError } from "@/lib/errors";
import { useMobileI18n } from "@/state/i18n";
import { useMobileSession } from "@/state/mobile-session";
import { searchResultView } from "@/view-models/buyer";
import {
  Card,
  ErrorState,
  Heading,
  LoadingState,
  Screen,
  styles,
} from "@/ui/primitives";
import { theme, tokens } from "@/theme/tokens";
import type { ProductSearchResponse } from "@/types/api";

export default function SearchScreen() {
  const { t, locale } = useMobileI18n();
  const { api, activeOrganization, activeLocation } = useMobileSession();
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<"relevance" | "lowest_normalized_price">(
    "relevance",
  );
  const [freshOnly, setFreshOnly] = useState(false);
  const [result, setResult] = useState<ProductSearchResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const search = async () => {
    if (
      activeOrganization === null ||
      activeLocation === null ||
      query.trim() === ""
    ) {
      return;
    }
    setLoading(true);
    setError(null);
    try {
      setResult(
        await api.search(activeOrganization.id, {
          q: query.trim(),
          locationId: activeLocation.id,
          sort,
          freshness: freshOnly ? ["fresh"] : undefined,
        }),
      );
    } catch (reason) {
      setError(localizedMobileError(locale, reason));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Screen>
      <Heading>{t("search.title")}</Heading>
      <TextInput
        onChangeText={setQuery}
        placeholder={t("search.placeholder")}
        style={local.input}
        value={query}
      />
      <Pressable
        onPress={() =>
          setSort(
            sort === "relevance" ? "lowest_normalized_price" : "relevance",
          )
        }
        style={styles.button}
      >
        <Text style={styles.buttonText}>
          {sort === "relevance"
            ? t("mobile.sortByNormalized")
            : t("search.sorting")}
        </Text>
      </Pressable>
      <Pressable onPress={() => setFreshOnly(!freshOnly)} style={styles.button}>
        <Text style={styles.buttonText}>
          {freshOnly ? t("mobile.allFreshness") : t("mobile.freshOnly")}
        </Text>
      </Pressable>
      <Pressable onPress={() => void search()} style={styles.button}>
        <Text style={styles.buttonText}>{t("common.search")}</Text>
      </Pressable>
      {loading ? <LoadingState /> : null}
      {error === null ? null : (
        <ErrorState message={error} onRetry={() => void search()} />
      )}
      {result?.results.map((item) => {
        const view = searchResultView(item, locale);
        return (
          <Card key={item.canonical_product.id}>
            <Text>{view.title}</Text>
            <Text style={styles.muted}>
              {view.price} · {view.supplierCount} {t("mobile.suppliers")}
            </Text>
            <Text style={styles.muted}>
              {t("mobile.freshnessSummary", {
                fresh: item.freshness_summary.fresh,
                aging: item.freshness_summary.aging,
                stale: item.freshness_summary.stale,
              })}
            </Text>
            <Text style={styles.muted}>
              {item.suppliers
                .map((supplier) => supplier.display_name)
                .join(", ")}
            </Text>
            <Pressable
              onPress={() =>
                router.push(`/(app)/compare/${item.canonical_product.id}`)
              }
              style={styles.button}
            >
              <Text style={styles.buttonText}>
                {t("comparison.viewComparison")}
              </Text>
            </Pressable>
          </Card>
        );
      })}
    </Screen>
  );
}

const local = StyleSheet.create({
  input: {
    backgroundColor: theme.surface,
    borderColor: theme.border,
    borderRadius: tokens.radius.sm,
    borderWidth: 1,
    minHeight: tokens.touchTarget.minimum,
    paddingHorizontal: tokens.spacing[3],
  },
});
