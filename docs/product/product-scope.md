# Product Scope

## Vision

CProviderPrices helps restaurants and hospitality businesses understand supplier pricing, organize vendor relationships, compare purchasing options, and make more informed purchasing decisions. It is a commercial multi-tenant SaaS product, not a single-company internal tool.

## Customers and market

The initial customers are restaurant and hospitality operators, buyers, and managers, beginning in North Carolina, USA. The product is intended to expand to other US markets. Geography-specific behavior must therefore be configurable or explicitly justified rather than embedded as a default domain assumption.

## MVP goals

The MVP should establish a secure and useful purchasing workflow that can:

- isolate each customer/company and its users, vendors, pricing, and operational data;
- represent suppliers and customer-specific supplier relationships;
- ingest and retain supplier product and price information with provenance;
- normalize differing supplier identifiers, descriptions, units, and case sizes;
- help users search, filter, and compare credible purchasing options;
- preserve price history sufficiently to explain when and where a price was observed; and
- provide a maintainable foundation for later automation and mobile access.

Major capability areas include authentication and authorization, tenant and user management, vendor management, supplier ingestion, catalog normalization and matching, price history, product comparison, and purchasing analysis. Their detailed requirements belong to later prompts.

## Initial non-goals

Unless a later prompt explicitly adds them, the initial MVP does not aim to provide:

- a nationwide supplier marketplace at launch;
- a full accounting, ERP, inventory, point-of-sale, or payment-processing replacement;
- fully automatic product matching with no review or uncertainty handling;
- a native mobile application in the first web MVP;
- supplier-specific business logic embedded throughout the core product; or
- speculative enterprise features unrelated to validating the core comparison workflow.

## SaaS and experience principles

Tenant isolation and server-side authorization are mandatory at every layer. The web experience should favor fast navigation, minimal unnecessary reloads and requests, responsive layouts, and clear system feedback. Initial workflows are desktop-first for hospitality operations, while APIs and domain contracts must remain reusable for future Expo mobile support.
