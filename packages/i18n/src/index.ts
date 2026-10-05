import { en } from "./locales/en";
import { es } from "./locales/es";

export const supportedLocales = ["en", "es"] as const;
export type SupportedLocale = (typeof supportedLocales)[number];
export interface Dictionary {
  [key: string]: string | Dictionary;
}

export const dictionaries: Record<SupportedLocale, Dictionary> = { en, es };

export function isSupportedLocale(value: unknown): value is SupportedLocale {
  return (
    typeof value === "string" &&
    supportedLocales.includes(value as SupportedLocale)
  );
}

export function localeTag(locale: SupportedLocale): "en-US" | "es-ES" {
  return locale === "es" ? "es-ES" : "en-US";
}

function lookup(dictionary: Dictionary, key: string): string | undefined {
  const value = key
    .split(".")
    .reduce<string | Dictionary | undefined>(
      (current, part) =>
        current && typeof current !== "string" ? current[part] : undefined,
      dictionary,
    );
  return typeof value === "string" ? value : undefined;
}

export function translate(
  locale: SupportedLocale | string | null | undefined,
  key: string,
  values: Record<string, string | number> = {},
): string {
  const selected = isSupportedLocale(locale) ? locale : "en";
  const template =
    lookup(dictionaries[selected], key) ?? lookup(en, key) ?? key;
  return template.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, name: string) =>
    values[name] === undefined ? `{${name}}` : String(values[name]),
  );
}

function leaves(dictionary: Dictionary, prefix = ""): string[] {
  return Object.entries(dictionary).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return typeof value === "string" ? [path] : leaves(value, path);
  });
}

export function dictionaryParityIssues(): string[] {
  const english = new Set(leaves(en));
  const spanish = new Set(leaves(es));
  return [
    ...[...english]
      .filter((key) => !spanish.has(key))
      .map((key) => `Spanish missing ${key}`),
    ...[...spanish]
      .filter((key) => !english.has(key))
      .map((key) => `English missing ${key}`),
  ];
}

export function formatNumber(
  value: number | string,
  locale: SupportedLocale,
  options: Intl.NumberFormatOptions = {},
): string {
  return new Intl.NumberFormat(localeTag(locale), options).format(
    Number(value),
  );
}

export function formatMoney(
  value: number | string,
  currency: string,
  locale: SupportedLocale,
): string {
  return formatNumber(value, locale, { style: "currency", currency });
}

export function formatPercentage(
  value: number | string,
  locale: SupportedLocale,
): string {
  return formatNumber(value, locale, { style: "percent" });
}

export function formatDateTime(
  value: string | Date,
  locale: SupportedLocale,
  timeZone: string = "UTC",
  options: Intl.DateTimeFormatOptions = {},
): string {
  return new Intl.DateTimeFormat(localeTag(locale), {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone,
    ...options,
  }).format(new Date(value));
}

export function formatDate(
  value: string | Date,
  locale: SupportedLocale,
  timeZone: string = "UTC",
): string {
  return new Intl.DateTimeFormat(localeTag(locale), {
    dateStyle: "medium",
    timeZone,
  }).format(new Date(value));
}

export function formatRelativeTime(
  value: string | Date,
  locale: SupportedLocale,
  now: Date = new Date(),
): string {
  const seconds = Math.round(
    (new Date(value).getTime() - now.getTime()) / 1000,
  );
  const unit =
    Math.abs(seconds) < 60
      ? "second"
      : Math.abs(seconds) < 3600
        ? "minute"
        : Math.abs(seconds) < 86400
          ? "hour"
          : "day";
  const divisor =
    unit === "second"
      ? 1
      : unit === "minute"
        ? 60
        : unit === "hour"
          ? 3600
          : 86400;
  return new Intl.RelativeTimeFormat(localeTag(locale), {
    numeric: "auto",
  }).format(Math.round(seconds / divisor), unit);
}

export function localizeApiError(
  locale: SupportedLocale,
  code: string,
): string {
  const key = `errors.${code}`;
  const translated = translate(locale, key);
  return translated === key
    ? translate(locale, "errors.request_failed")
    : translated;
}
