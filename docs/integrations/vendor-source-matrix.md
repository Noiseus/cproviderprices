# Supplier sources for Prompts 11 and 12

This document supplements [supplier integration principles](suppliers.md). It records application support, not claims about supplier export or API capabilities.

| Stable definition code | Display name | Approved ingestion path |
| --- | --- | --- |
| us-foods | US Foods | Customer-provided CSV/XLSX |
| sysco | Sysco | Customer-provided CSV/XLSX |
| performance-foodservice | Performance Foodservice | Customer-provided CSV/XLSX |
| freshpoint | FreshPoint | Customer-provided CSV/XLSX |
| vistar | Vistar | Customer-provided CSV/XLSX |
| prestige | Prestige | Customer-provided CSV/XLSX |

`SupplierDefinitionSeeder` remains the source of stable global supplier identities. An organization/location supplier account is separate customer-owned metadata. Imports must reference that existing account and validate its organization and location; a supplier definition alone grants no access.

Headers, delimiters, worksheets, abbreviations, SKU types, packaging, and unit conventions may vary even within one supplier. No universal supplier column layout is assumed. Reusable templates belong to the organization and supplier account/source format, record their source headers and unit conventions, and are copied into each import as an immutable mapping snapshot. Headers or samples from another tenant must never be disclosed.

Manual imports require the centralized `manual_imports_enabled` entitlement. Source files, samples, mappings, raw records, and normalized results remain private tenant data. Future automatic synchronization is a metadata placeholder only. Prompts 11/12 implement no supplier API, scraping, portal login, passwords, MFA secrets, or cookies. See [credential handling](../security/credential-handling.md).
