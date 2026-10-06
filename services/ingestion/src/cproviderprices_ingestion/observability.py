"""Small, secret-safe structured events for the private ingestion boundary."""

import json
import logging


def log_event(event: str, **context: object) -> None:
    """Emit only caller-selected scalar operational context, never request bodies."""
    payload = {"event": event, **context}
    logging.getLogger("cproviderprices.observability").info(
        json.dumps(payload, separators=(",", ":"), sort_keys=True)
    )
