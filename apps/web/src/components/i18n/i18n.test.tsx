import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  dictionaries,
  dictionaryParityIssues,
  formatDateTime,
  formatNumber,
  formatRelativeTime,
  localizeApiError,
  translate,
} from "@cproviderprices/i18n";
import { afterEach, describe, expect, it, vi } from "vitest";
import { LanguageSelector } from "./LanguageSelector";
import { I18nProvider, useTranslation } from "./I18nProvider";

function LocalizedProbe() {
  const { t } = useTranslation();
  return <p>{t("nav.dashboard")}</p>;
}

describe("internationalization", () => {
  afterEach(() => vi.restoreAllMocks());

  it("keeps English and Spanish dictionaries structurally aligned", () => {
    expect(dictionaryParityIssues()).toEqual([]);
  });

  it("falls back to English when a Spanish presentation key is absent", () => {
    const navigation = dictionaries.es.nav as Record<string, string>;
    const original = navigation.dashboard;
    delete navigation.dashboard;

    expect(translate("es", "nav.dashboard")).toBe("Dashboard");

    navigation.dashboard = original;
  });

  it("formats numbers, time zones, freshness, and stable API codes by locale", () => {
    expect(formatNumber("12345.6", "en", { minimumFractionDigits: 2 })).toBe(
      "12,345.60",
    );
    expect(formatNumber("12345.6", "es", { minimumFractionDigits: 2 })).toBe(
      "12.345,60",
    );
    expect(
      formatDateTime("2026-01-01T02:30:00Z", "en", "America/New_York"),
    ).toContain("Dec 31, 2025");
    expect(
      formatRelativeTime(
        "2026-01-01T00:00:00Z",
        "es",
        new Date("2026-01-01T00:05:00Z"),
      ),
    ).toMatch(/hace|atrás/);
    expect(localizeApiError("es", "permission_denied")).toBe(
      "No tiene permiso para realizar esta acción.",
    );
  });

  it("persists the selected language through the existing authenticated API without a reload", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .spyOn(global, "fetch")
      .mockResolvedValue(
        new Response(
          JSON.stringify({ session: { user: { preferred_language: "es" } } }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      );
    const marker = vi.fn();
    window.addEventListener("beforeunload", marker);

    render(
      <I18nProvider initialLocale="en">
        <LanguageSelector />
        <LocalizedProbe />
      </I18nProvider>,
    );

    await user.selectOptions(screen.getByLabelText("Language"), "es");

    expect(await screen.findByText("Panel")).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/v1/auth/preferences"),
      expect.objectContaining({ method: "PATCH", credentials: "include" }),
    );
    expect(marker).not.toHaveBeenCalled();
    window.removeEventListener("beforeunload", marker);
  });
});
// @vitest-environment jsdom
