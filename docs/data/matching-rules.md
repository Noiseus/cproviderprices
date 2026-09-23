# Boundary for future matching

Prompt 12 performs no canonical matching. It prepares traceable evidence under the [normalization contract](unit-normalization.md) and [catalog boundary](canonical-product-model.md).

Future Prompt 13 consideration order is identifier, verified mapping, exact normalized attributes, aliases/text, then weighted attribute candidate generation. This order does not authorize automatic acceptance. Identifier namespace, scope and uniqueness need verification; supplier SKUs are not automatically global identifiers. A verified mapping must remain valid for its tenant/supplier/packaging context. Exact attributes must include compatible dimensions and packaging. Text alone is insufficient evidence of equivalence.

Uncertain candidates must never silently become exact matches. Preserve both raw descriptions and normalized attributes, including absent or conflicting evidence. Ambiguous, variable-weight, unsupported-unit, or unsafe parser results reduce eligibility for future automatic matching. Parser `high`/`medium`/`low` expresses extraction quality only; it is not product-match confidence or classes A-E.

Prompt 13 owns match classes, scoring weights, review, acceptance thresholds, corrections, and publication rules. They remain undefined here. No vector search, embeddings, LLM matching, catalog tables, or match implementation is authorized by this document.
