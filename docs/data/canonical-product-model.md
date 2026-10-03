# Normalized output and the future catalog boundary

This supplements [data principles](data-principles.md). It is a conceptual boundary, not a Prompt 13 schema approval.

- Raw supplier record: immutable source cells, row number, file/run identity, and confirmed mapping snapshot in tenant scope.
- Normalized import record: versioned deterministic extraction from exactly one raw record. It may contain unresolved ambiguity; it is not a catalog item.
- Future vendor item: supplier/account-specific identity whose SKU stability and history require later decisions.
- Future canonical product: reviewed product concept independent of any one supplier description.
- Future product variant: packaging or other meaningful attributes that must not be erased to manufacture equivalence.
- Future aliases/translations: contextual naming evidence, never proof of equivalent units or packaging.
- Future mappings: explicit, evidenced relationships between vendor items and canonical entities, subject to future verification and review rules.

Prompt 12 ends at normalized import output. Preserve raw description, SKU as text (including leading zeroes), explicit brand, original pack string, pack count, unit size, case total, dimension, original and canonical units/values, size-code evidence, flags, warning codes, confidence, source conventions, parser version, and organization/location/supplier-account/run/file/raw-row provenance. Never guess brand, category, identifiers, translations, or product equivalence from names.

No canonical_products, product_variants, aliases, matching candidates, review tables, or catalog publication behavior may be introduced in Prompts 11/12. Future matching consumes this evidence; it must not mutate historical raw or normalized records.
