FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN useradd --system --uid 10003 --create-home --shell /usr/sbin/nologin runtime-agent \
    && install -d -o runtime-agent -g runtime-agent -m 0700 /data

COPY agent.py /app/agent.py

USER runtime-agent

CMD ["python", "/app/agent.py"]
