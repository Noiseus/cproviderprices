# Data Principles

This document establishes conceptual rules, not a final database schema. Detailed entities, constraints, indexes, retention, and migrations will be designed in a later data-model prompt.

## Tenant-aware ownership

Customer-owned data must carry an unambiguous tenant/company relationship, directly or through a safely enforced ownership chain. Every read and write must apply server-resolved tenant context. Client-provided tenant identifiers are selectors at most, never proof of authorization. Cross-tenant administrative operations require explicit privileged paths and auditing.

## Core concepts

- **Supplier:** an external vendor or source organization, such as a broadline distributor or specialty supplier.
- **Product:** a normalized concept used to compare purchasing options; it must not erase meaningful packaging or quality differences.
- **Supplier product:** a supplier's offer or catalog representation of an item, distinct from the normalized product.
- **Supplier SKU:** a source-specific identifier whose uniqueness and stability must be evaluated within supplier/account context.
- **Unit and case size:** structured packaging and quantity information needed for valid price comparison. Source text should be preserved when parsing is uncertain.
- **Price observation:** the price seen for a supplier product, customer/account context, effective or observed time, currency, quantity basis, and source. Observations form history rather than overwriting the past.

Some supplier pricing may be customer-specific. Data models and access paths must not accidentally promote a customer's contracted price into a global price visible to other tenants.

## Normalization and matching

Supplier names, descriptions, identifiers, brands, pack sizes, units, and categories vary. Normalization converts source values into structured candidates while retaining the original values. Matching relates supplier products to equivalent or comparable normalized products and should support confidence, evidence, review status, and corrections.

Product name alone is never sufficient evidence for a match. Matching may consider manufacturer identifiers, brand, size, unit, pack count, category, attributes, customer feedback, and reviewed mappings. Comparisons must account for unit and case-size differences and disclose uncertainty.

## Provenance and history

Ingested records must preserve enough context to answer where a value came from, when it was received and processed, which connector and source reference produced it, and what transformations occurred. Store relevant ingestion timestamps separately from source effective dates when both exist.

Ingestion should be idempotent where practical, report partial failures, retain actionable error context without leaking secrets, and support audit or replay needs. Corrections should not silently destroy price history or source evidence. Retention requirements and exact audit structures remain future decisions.
