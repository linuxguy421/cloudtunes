FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    && useradd --system --uid 10001 --create-home --shell /usr/sbin/nologin runtime \
    && mkdir -p /data \
    && chown runtime:runtime /data

COPY app.py .
USER runtime

EXPOSE 8090
CMD ["uvicorn", "app:app", "--host=0.0.0.0", "--port=8090", "--workers=1"]
