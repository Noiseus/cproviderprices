import {
  isSupportedLocale,
  translate,
  type SupportedLocale,
} from "@cproviderprices/i18n";
import {
  createContext,
  useContext,
  useMemo,
  type PropsWithChildren,
} from "react";

interface MobileI18nValue {
  locale: SupportedLocale;
  t: (key: string, values?: Record<string, string | number>) => string;
}

const MobileI18nContext = createContext<MobileI18nValue | null>(null);

export function MobileI18nProvider({
  locale,
  children,
}: PropsWithChildren<{ locale: string }>) {
  const selectedLocale = isSupportedLocale(locale) ? locale : "en";
  const value = useMemo<MobileI18nValue>(
    () => ({
      locale: selectedLocale,
      t: (key, values) => translate(selectedLocale, key, values),
    }),
    [selectedLocale],
  );

  return (
    <MobileI18nContext.Provider value={value}>
      {children}
    </MobileI18nContext.Provider>
  );
}

export function useMobileI18n(): MobileI18nValue {
  const value = useContext(MobileI18nContext);
  if (value === null) {
    throw new Error(
      "useMobileI18n must be rendered within MobileI18nProvider.",
    );
  }

  return value;
}
