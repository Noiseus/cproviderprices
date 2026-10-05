"use client";

import type { SupportedLocale } from "@cproviderprices/i18n";
import { Select } from "@/components/ui";
import { useTranslation } from "./I18nProvider";

export function LanguageSelector({ compact = false }: { compact?: boolean }) {
  const { locale, t, setPreferredLanguage, savingLanguage, languageError } =
    useTranslation();

  return (
    <div aria-live="polite">
      <Select
        className={
          compact
            ? "language-selector language-selector--compact"
            : "language-selector"
        }
        label={t("settings.languageLabel")}
        hint={languageError ?? undefined}
        value={locale}
        disabled={savingLanguage}
        onChange={(event) => {
          void setPreferredLanguage(
            event.target.value as SupportedLocale,
          ).catch(() => undefined);
        }}
      >
        <option value="en">{t("nav.english")}</option>
        <option value="es">{t("nav.spanish")}</option>
      </Select>
    </div>
  );
}
