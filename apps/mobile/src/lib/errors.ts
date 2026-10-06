import { localizeApiError, type SupportedLocale } from "@cproviderprices/i18n";

export class MobileApiError extends Error {
  public constructor(
    public readonly code: string,
    public readonly status: number | null,
    public readonly requestId: string | null,
    public readonly details: Record<string, unknown> | null = null,
  ) {
    super(code);
  }
}

export function localizedMobileError(
  locale: SupportedLocale,
  error: unknown,
): string {
  if (error instanceof MobileApiError) {
    return localizeApiError(locale, error.code);
  }

  return localizeApiError(locale, "network_error");
}
