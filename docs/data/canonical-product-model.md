# Normalized output and the future catalog boundary

This supplements [data principles](data-principles.md). Prompt 13 implements
the catalog/matching boundary below; it remains deliberately narrow and is not
a product search schema.

- Raw supplier record: immutable source cells, row number, file/run identity, and confirmed mapping snapshot in tenant scope.
- Normalized import record: versioned deterministic extraction from exactly one raw record. It may contain unresolved ambiguity; it is not a catalog item.
- Vendor item: tenant-private supplier-account-specific identity. Supplier SKU is
  scoped identity; a missing SKU falls back to a deterministic evidence key.
  It retains first/current normalized-record provenance, original description,
  normalized attributes, parser version, and no price history.
- Canonical product: globally reusable reviewed English product concept, in a
  stable category. Supplier descriptions never become canonical names by copy.
- Product variant: meaningful product distinction (for example form, grade,
  size code or variant-defining brand), not a supplier pack record.
- Aliases/translations: normalized English/Spanish naming evidence, never proof
  of equivalent units or packaging.
- Product identifiers: globally reliable typed identifiers only; a supplier SKU
  cannot be promoted into this namespace.
- Mappings/candidates/reviews: explicit versioned evidence and history joining a
  private vendor item to a shared canonical product or variant.

Prompt 12 ends at normalized import output. Preserve raw description, SKU as text (including leading zeroes), explicit brand, original pack string, pack count, unit size, case total, dimension, original and canonical units/values, size-code evidence, flags, warning codes, confidence, source conventions, parser version, and organization/location/supplier-account/run/file/raw-row provenance. Never guess brand, category, identifiers, translations, or product equivalence from names.

The catalog tables are global (`categories`, translations, canonical products,
variants, aliases and public identifiers). Vendor items, candidates, mappings
and review context retain organization/supplier scope. Global catalog responses
must never include tenant supplier accounts, pricing, raw files or private
import records. Matching consumes evidence but never mutates historical raw or
normalized records.
