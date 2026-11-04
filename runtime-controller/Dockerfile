FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

RUN useradd --system --uid 10001 --create-home --shell /usr/sbin/nologin runtime \
    && mkdir -p /data \
    && chown runtime:runtime /data

# Keep the test target explicit so normal Compose builds produce the service image.
FROM base AS test

COPY requirements.txt .
COPY requirements-dev.txt .
RUN pip install --no-cache-dir -r requirements-dev.txt

COPY app.py .
COPY tests ./tests

CMD ["python", "-m", "pytest", "-q", "tests"]

# The runtime stage is last, making it the default image for Compose builds.
FROM base AS runtime

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .
USER runtime

EXPOSE 8090
CMD ["uvicorn", "app:app", "--host=0.0.0.0", "--port=8090", "--workers=1"]
