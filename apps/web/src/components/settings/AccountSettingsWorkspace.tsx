"use client";

import { Card } from "@/components/ui";
import { ThemeToggle } from "@/components/ThemeToggle";
import { LanguageSelector } from "@/components/i18n/LanguageSelector";
import { useTranslation } from "@/components/i18n/I18nProvider";

export function AccountSettingsWorkspace() {
  const { t, savingLanguage, languageError, languageSaved } = useTranslation();

  return (
    <main className="ui-container">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">{t("nav.account")}</p>
          <h1>{t("settings.title")}</h1>
          <p>{t("settings.description")}</p>
        </div>
      </header>
      <Card>
        <h2>{t("settings.languageLabel")}</h2>
        <p>{t("settings.languageHelp")}</p>
        <LanguageSelector />
        {savingLanguage && <p role="status">{t("common.saving")}</p>}
        {languageSaved && !languageError && (
          <p role="status">{t("settings.languageSaved")}</p>
        )}
      </Card>
      <Card>
        <h2>{t("nav.theme")}</h2>
        <ThemeToggle />
      </Card>
    </main>
  );
}
