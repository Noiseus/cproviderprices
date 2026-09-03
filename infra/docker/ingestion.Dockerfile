FROM python:3.14.0-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app/src

WORKDIR /app

COPY services/ingestion/requirements.lock ./requirements.lock
RUN python -m pip install --no-cache-dir --require-hashes -r requirements.lock

COPY services/ingestion ./

EXPOSE 8001

CMD ["uvicorn", "cproviderprices_ingestion.main:app", "--host", "0.0.0.0", "--port", "8001"]
