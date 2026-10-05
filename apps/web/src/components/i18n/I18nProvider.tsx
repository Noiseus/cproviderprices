"use client";

import {
  formatDate,
  formatDateTime,
  formatMoney,
  formatNumber,
  formatPercentage,
  formatRelativeTime,
  isSupportedLocale,
  localizeApiError,
  translate,
  type SupportedLocale,
} from "@cproviderprices/i18n";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { ApiClientError, apiRequest } from "@/lib/api";

type TranslationValues = Record<string, string | number>;

export type I18nContextValue = {
  locale: SupportedLocale;
  t: (key: string, values?: TranslationValues) => string;
  setPreferredLanguage: (locale: SupportedLocale) => Promise<void>;
  savingLanguage: boolean;
  languageSaved: boolean;
  languageError: string | null;
  formatNumber: (
    value: number | string,
    options?: Intl.NumberFormatOptions,
  ) => string;
  formatMoney: (value: number | string, currency: string) => string;
  formatPercentage: (value: number | string) => string;
  formatDate: (value: string | Date, timeZone?: string) => string;
  formatDateTime: (value: string | Date, timeZone?: string) => string;
  formatRelativeTime: (value: string | Date) => string;
  localizeError: (code: string) => string;
};

const englishFallback: I18nContextValue = {
  locale: "en",
  t: (key, values) => translate("en", key, values),
  setPreferredLanguage: async () => undefined,
  savingLanguage: false,
  languageSaved: false,
  languageError: null,
  formatNumber: (value, options) => formatNumber(value, "en", options),
  formatMoney: (value, currency) => formatMoney(value, currency, "en"),
  formatPercentage: (value) => formatPercentage(value, "en"),
  formatDate: (value, timeZone) => formatDate(value, "en", timeZone),
  formatDateTime: (value, timeZone) => formatDateTime(value, "en", timeZone),
  formatRelativeTime: (value) => formatRelativeTime(value, "en"),
  localizeError: (code) => localizeApiError("en", code),
};

const I18nContext = createContext<I18nContextValue>(englishFallback);

export function I18nProvider({
  initialLocale = "en",
  children,
}: {
  initialLocale?: SupportedLocale | string | null;
  children: ReactNode;
}) {
  const [locale, setLocale] = useState<SupportedLocale>(
    isSupportedLocale(initialLocale) ? initialLocale : "en",
  );
  const [savingLanguage, setSavingLanguage] = useState(false);
  const [languageSaved, setLanguageSaved] = useState(false);
  const [languageError, setLanguageError] = useState<string | null>(null);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setPreferredLanguage = useCallback(
    async (next: SupportedLocale) => {
      if (next === locale) return;
      setSavingLanguage(true);
      setLanguageSaved(false);
      setLanguageError(null);
      try {
        const response = await apiRequest<{
          session: { user: { preferred_language: SupportedLocale } };
        }>("/api/v1/auth/preferences", {
          method: "PATCH",
          body: JSON.stringify({ preferred_language: next }),
        });
        setLocale(response.session.user.preferred_language);
        setLanguageSaved(true);
      } catch (caught) {
        setLanguageError(
          caught instanceof ApiClientError
            ? localizeApiError(locale, caught.code)
            : localizeApiError(locale, "network_error"),
        );
        throw caught;
      } finally {
        setSavingLanguage(false);
      }
    },
    [locale],
  );

  const value = useMemo<I18nContextValue>(
    () => ({
      locale,
      t: (key, values) => translate(locale, key, values),
      setPreferredLanguage,
      savingLanguage,
      languageSaved,
      languageError,
      formatNumber: (number, options) => formatNumber(number, locale, options),
      formatMoney: (amount, currency) => formatMoney(amount, currency, locale),
      formatPercentage: (number) => formatPercentage(number, locale),
      formatDate: (date, timeZone) => formatDate(date, locale, timeZone),
      formatDateTime: (date, timeZone) =>
        formatDateTime(date, locale, timeZone),
      formatRelativeTime: (date) => formatRelativeTime(date, locale),
      localizeError: (code) => localizeApiError(locale, code),
    }),
    [
      languageError,
      languageSaved,
      locale,
      savingLanguage,
      setPreferredLanguage,
    ],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useTranslation(): I18nContextValue {
  return useContext(I18nContext);
}
