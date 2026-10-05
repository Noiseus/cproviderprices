# Internationalization

## Language model

English (`en`) is the base and fallback language. Spanish (`es`) is a presentation and search-interpretation language; it does not create a second canonical catalog, alter supplier-source fields, or change matching and comparison decisions.

`users.preferred_language` is a server-authorized `en`/`es` preference with an English default. The authenticated session exposes it, and the CSRF-protected `PATCH /api/v1/auth/preferences` endpoint updates it. The web client applies the returned preference in place, so changing language preserves the current route, query state, session, organization, and selected location.

## Presentation and contracts

The `@cproviderprices/i18n` package owns deterministic English and Spanish dictionaries, English-key fallback, message interpolation, stable API-error-code localization, and `Intl` number, currency, percentage, date, date-time, and relative-time formatting. UI code must use these helpers instead of browser-locale defaults. Location timestamps use the selected restaurant location's configured timezone when that context is available.

Public API enum values, error codes, canonical English product names, source supplier names, SKUs, raw descriptions, imported files, matching evidence, and comparison calculations remain locale-neutral. The API continues to return stable codes; clients translate those codes at the presentation boundary.

## Search aliases

Catalog aliases are stored separately from canonical products. Spanish aliases such as `tomate`, `pechuga de pollo`, `aceite de canola`, and `queso mozzarella` resolve to the existing English canonical products. They are not supplier translations and do not modify source rows or create translated canonical duplicates.

## Verification

Dictionary parity and English fallback are unit-tested. Backend tests cover preference validation and alias integrity. Browser smoke coverage checks live language switching, preserved state, Spanish aliases, localized comparison/list distinctions, responsive navigation, and fallback to English.
