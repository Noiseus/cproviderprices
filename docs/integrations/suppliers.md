# Supplier Integrations

Supplier data may arrive through APIs, files, portals, scheduled exports, or future automated imports. Formats, identifiers, authentication, update cadence, data quality, account scoping, and error behavior will differ.

Supplier integrations must therefore use adapters/connectors rather than supplier-specific logic spread throughout the application. A connector translates a source into stable internal ingestion contracts while keeping credentials, transport, parsing, retry behavior, and source quirks localized.

## Connector principles

- Treat every external payload and file as untrusted input.
- Preserve raw/source references and provenance where policy permits.
- Record retrieval, source, processing, and effective timestamps distinctly when applicable.
- Support frequent price changes, including daily updates, without overwriting useful history.
- Preserve supplier SKU, original description, unit/case information, currency, and customer/account scope.
- Make imports auditable and surface actionable errors, partial failures, and retry outcomes.
- Prefer idempotent processing and explicit duplicate handling.
- Keep secrets out of source control, logs, and user-visible errors.
- Do not assume product names establish equivalence across suppliers.

## Initial ecosystem examples

Potential early sources include US Foods, Performance Foodservice, Sysco, Prestige, Vistar, and FreshPoint. These are examples for integration planning, not commitments, endorsements, fixed requirements, or hard-coded architectural assumptions. The supported set and integration method for each supplier will be established through later product and integration work.
