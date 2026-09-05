# CProviderPrices Ingestion

Minimal internal Python HTTP service for future supplier ingestion and normalization. Prompt 01 includes only typed configuration and `GET /health`; connectors and domain processing are intentionally deferred.

The local Docker stack publishes port `8001` for developer smoke testing. The service remains internal in the intended production topology.
