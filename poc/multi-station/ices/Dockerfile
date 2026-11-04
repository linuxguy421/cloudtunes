FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends ices2 ffmpeg python3 util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --no-create-home --shell /usr/sbin/nologin ices \
    && mkdir -p /var/log/ices /media /playlist

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY render_config.py /usr/local/bin/render_config.py
COPY check_mount.py /usr/local/bin/check_mount.py
RUN chmod 0755 /usr/local/bin/entrypoint.sh /usr/local/bin/check_mount.py

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
