# Canonical matching rules v1

Prompt 13 consumes immutable Prompt 12 normalization evidence. It does not mutate
raw or normalized import rows, and it does not use embeddings, vector search,
external NLP, or an LLM. The deterministic matcher version is
`catalog-matcher-1.0.0`; a later matcher must use a new version rather than
reinterpret historical evidence.

## Stage order and bounded candidate generation

Stages execute in this order and use an explicit deterministic tie-break of
score descending, then canonical target key ascending:

1. A public structured identifier (`gtin`, `upc`, or `plu`) is compared after
   normalization of its namespace/value. A supplier SKU is vendor-item identity,
   never a global product identifier.
2. A current verified mapping wins and suppresses fresh candidate generation.
3. Exact normalized attributes require canonical normalized name plus supplied,
   compatible structured attributes. Missing values are not guessed.
4. English/Spanish aliases use normalized deterministic tokens. They generate a
   candidate, not an exact match by text alone.
5. Weighted attributes run only over a bounded, text-prefiltered set (at most 25
   targets). Identifier, exact attributes and complete aliases have weights of
   100, 95 and 80; each token overlap adds 20 and each compatible structured
   attribute adds 10, subject to the documented cap recorded in evidence.

Known conflicting `family`, `form`, `variety`, `size_code`, or measurement
dimension is a hard contradiction. It prevents `exact_match`; weak text cannot
outweigh it. A `5X6` produce size code is retained as size evidence, never
multiplied. Pack differences alone do not create a new canonical concept.

## Classes, confidence, and auto-publication

`exact_match` means the same product/variant under rule-qualified evidence.
`equivalent_product` means a safe reusable product concept with supplier pack
differences retained separately. `comparable_alternative` and
`related_product` may assist a reviewer but are not exact. `not_comparable`
records a hard conflict.

Product-match confidence is `A` through `E`, and is completely separate from
Prompt 12 parser `high`/`medium`/`low` extraction confidence. `A` is reliable
identifier or exact structured evidence; `B` is complete approved alias plus
no contradiction; `C` is meaningful but incomplete evidence; `D` is weak;
`E` contains a contradiction or is only minimally related. Candidate evidence
stores stage, weights, matched aliases/identifiers, structured matches,
differences, contradictions, parser context, and matcher version.

Only an `A` `exact_match` from a reliable identifier, or from exact normalized
name plus at least two compatible structured attributes with a high,
warning-free, non-variable parser result, may auto-publish. Every other
candidate remains `pending`. In particular, a lower-confidence candidate can
never appear as a verified exact mapping before a platform reviewer acts.

## Review, rejection memory, and history

Platform administrators may accept, reject, choose an existing canonical
product/variant, create a canonical product/variant, or reverse a current
mapping. A new manual mapping supersedes (rather than overwrites) the former
current mapping. Reviews retain actor, action, reason, safe previous/resulting
state, candidate fingerprint, and timestamp; audit metadata contains only IDs,
action and matcher version, never supplier files or prices.

Candidate replay identity includes the vendor-item evidence fingerprint,
canonical target, and matcher version. A rejection with unchanged evidence and
version is returned as the existing rejected candidate rather than regenerated
as pending. Changed evidence or a new matcher version permits reconsideration.
