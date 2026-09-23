# Deterministic normalization contract v1

This is the authoritative Prompt 12 extraction contract. It defines parser behavior, not product equivalence or pricing. Preserve the entire original mapped record, raw description, pack text, quantity text, original numeric lexeme, and original unit. Never overwrite source data. Output includes parser version, typed warnings, extraction confidence, and raw-record correlation identity.

## Dimensions and exact arithmetic

Use decimal arithmetic, not binary floating point. Canonical weight is grams (`g`), volume is millilitres (`mL`), and count is `ea`. Serialize decimals as base-10 strings without exponent notation or redundant trailing zeroes. Conversion factors below are exact; presentation rounding must not alter stored values.

| Input unit | Canonical multiplier | Dimension |
| --- | --- | --- |
| g | 1 g | weight |
| kg | 1000 g | weight |
| oz (avoirdupois) | 28.349523125 g | weight |
| lb (avoirdupois) | 453.59237 g | weight |
| mL | 1 mL | volume |
| L | 1000 mL | volume |
| US liquid gal | 3785.411784 mL | volume |
| US liquid qt | 946.352946 mL | volume |
| US liquid pt | 473.176473 mL | volume |
| US fl oz | 29.5735295625 mL | volume |
| ea, each, count, ct | 1 ea | count |

Unit tokens are case-insensitive. Accept singular/plural pound, ounce, gram, kilogram, litre/liter, millilitre/milliliter aliases. `oz` is weight, never fluid volume. Bare gal/qt/pt/fl oz require an explicit `us_liquid` source convention in the confirmed mapping or explicit US qualifier in the source; business address or supplier name is not evidence. Without it, retain the value/unit but return no canonical volume and warn `unit_system_required`. Imperial, dry-volume, troy, and other units are unsupported in v1. No weight/volume/count cross-conversions, density inference, or serving-size conversions are permitted.

## Pack grammar

An isolated positive integer `N/S UNIT` means N packages, each of positive decimal size S in UNIT. `N` is pack count, S is unit size, and case total is N times S only when the pack is fixed and unambiguous. `CS`/`CASE` identifies packaging, not a quantity. A standalone `S UNIT` denotes a measured size; do not invent pack count 1 or assert it is a case total. Explicit `12 CT` means count 12, not twelve cases. A separate case-count column counts cases; it does not establish units per case.

| Source example | Deterministic extraction |
| --- | --- |
| TOM BEEF 5X6 1/25 LB CS | pack 1; size 25 lb; total 11339.80925 g; preserve 5X6 as size code only when mapping explicitly declares produce-size semantics |
| TOM BEEFSTEAK 25# | size 25 lb only with explicit pound-sign weight convention; no invented pack count or case total |
| CHIX BRST BNLS SKNLS 4/10 LB | pack 4; size 10 lb; total 18143.6948 g |
| OIL CANOLA 6/1 GAL | pack 6; size 1 gal; total 22712.470704 mL only with `us_liquid` convention |
| MOZZ WHOLE MILK 4/5 LB | pack 4; size 5 lb; total 9071.8474 g |

`5X6` is never multiplied to obtain 30. A confirmed source mapping may declare `produce_size_code`; otherwise retain the token with `ambiguous_size_code` and leave interpreted size code null. Do not infer produce category from `TOM` or descriptions. `#` means pounds only with explicit mapped weight context or confirmed `pound_sign_is_weight` source convention; otherwise warn `ambiguous_unit`. Such conventions must be included in the input/versioned mapping snapshot so repeated parsing is deterministic.

## Uncertainty and conflicts

Mapped numeric fields and explicit pack text are evidence, not precedence rules. If they disagree, emit `conflicting_values`, preserve both, and suppress derived values for the affected dimension. Multiple competing pack candidates, ranges, fractions outside the supported grammar, locale-ambiguous numbers, missing units, negative/zero sizes, or noninteger pack counts must not be guessed. Explicit numbers may use ASCII digits and a decimal point; commas are ambiguous unless a future version supports an explicit locale.

Catch-weight/variable-weight indicators (`CW`, `CATCH WEIGHT`, `VARIABLE WEIGHT`, `RANDOM WEIGHT`) emit `variable_weight`. Nominal values may be preserved as evidence but no fixed normalized weight or case total is emitted. Do not infer actual shipment weight or average ranges. Unsupported units never produce canonical values. Independent safe fields may still be extracted when another field is uncertain.

## Warnings and extraction confidence

Warnings are stable objects `{code, field, message}`. Messages describe the issue without echoing supplier contents. Sort by field then code and deduplicate. Codes in v1: `missing_quantity`, `ambiguous_quantity`, `ambiguous_unit`, `ambiguous_pack`, `ambiguous_size_code`, `unit_system_required`, `unsupported_unit`, `unsafe_conversion`, `conflicting_values`, `variable_weight`. Missing SKU/description are import validation errors, separate from normalization warnings.

Parser confidence is the enum `high`, `medium`, `low`: high means at least one safe quantitative extraction and no warnings; medium means safe quantitative extraction remains but non-conflicting warnings exist; low means no safe quantitative extraction or any conflict, unsafe conversion, or variable-weight warning. This is deterministic extraction quality, never a probability or Prompt 13 match class. Version changes create new traceable output; do not silently replace historical results.
