FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends ices2 python3 util-linux ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10002 --no-create-home --shell /usr/sbin/nologin ices \
    && install -d -o ices -g ices -m 0750 /var/log/ices /media /playlist

COPY entrypoint.sh /usr/local/bin/cloudtunes-station-entrypoint
COPY render_config.py /usr/local/bin/render_config.py
COPY check_mount.py /usr/local/bin/check_mount.py

RUN chmod 0755 /usr/local/bin/cloudtunes-station-entrypoint /usr/local/bin/check_mount.py

STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/cloudtunes-station-entrypoint"]
