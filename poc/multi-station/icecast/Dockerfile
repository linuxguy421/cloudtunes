FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends icecast2 python3 curl util-linux \
    && rm -rf /var/lib/apt/lists/*

COPY render_config.py /usr/local/bin/render_config.py
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/log/icecast2

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
