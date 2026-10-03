"""Authenticate and bound ASGI bytes before FastAPI buffers/parses JSON."""

import hmac
import json
import re

from starlette.types import ASGIApp, Message, Receive, Scope, Send

MAX_BODY_BYTES = 1048576
PATH = "/internal/v1/normalize"


class NormalizationBoundary:
    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http" or scope["path"] != PATH:
            await self.app(scope, receive, send)
            return
        headers = {key.lower(): value for key, value in scope["headers"]}
        correlation = headers.get(b"x-request-id", b"").decode("ascii", errors="ignore")
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", correlation):
            correlation = "unassigned"
        scope["state"] = {**scope.get("state", {}), "request_id": correlation}

        async def reject(status: int, code: str) -> None:
            body = json.dumps({"error": {"code": code, "request_id": correlation}}).encode()
            await send(
                {
                    "type": "http.response.start",
                    "status": status,
                    "headers": [
                        (b"content-type", b"application/json"),
                        (b"x-request-id", correlation.encode()),
                        (b"cache-control", b"no-store"),
                    ],
                }
            )
            await send({"type": "http.response.body", "body": body})

        token: str = scope["app"].state.settings.service_token
        authorization = headers.get(b"authorization", b"")
        if not token.strip() or not hmac.compare_digest(authorization, b"Bearer " + token.encode()):
            await reject(401, "unauthorized_service")
            return
        if b"content-encoding" in headers:
            await reject(415, "unsupported_encoding")
            return
        if headers.get(b"content-type", b"").split(b";")[0].strip() != b"application/json":
            await reject(415, "unsupported_media_type")
            return
        length = headers.get(b"content-length")
        if length is not None:
            if not length.isdigit():
                await reject(400, "invalid_content_length")
                return
            if len(length) > 10 or int(length) > MAX_BODY_BYTES:
                await reject(413, "request_too_large")
                return
        buffered = bytearray()
        size = 0
        while True:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            chunk: bytes = message.get("body", b"")
            size += len(chunk)
            if size > MAX_BODY_BYTES:
                await reject(413, "request_too_large")
                return
            buffered.extend(chunk)
            if not message.get("more_body", False):
                break
        body = bytes(buffered)
        try:
            json.loads(body)
        except (ValueError, RecursionError):
            await reject(422, "invalid_request")
            return

        async def bounded_receive() -> Message:
            return {"type": "http.request", "body": body, "more_body": False}

        await self.app(scope, bounded_receive, send)
