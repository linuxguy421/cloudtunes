FROM ubuntu:latest

ARG DEBIAN_FRONTEND=noninteractive

MAINTAINER Greg Holthaus "<gerg@gerg.me>"

RUN \
	apt-get -qq update && \
	apt-get -qq -y upgrade && \
	apt-get -qq -y install ices2 python3-setuptools joe supervisor lltag normalize-audio ffmpeg

RUN mkdir -p /var/log/ices
RUN mkdir -p /etc/ices
ADD etc/ices/ /etc/ices

# Music and generated playlist state belong to persistent runtime volumes, not
# the image. Generate the playlist at startup after Compose mounts those volumes.
COPY container-files /
COPY supervisord-ices.conf /etc/supervisor.d/supervisord-ices.conf
RUN chmod 0755 /usr/local/bin/cloudtunes-entrypoint /usr/local/bin/generate-playlist.sh

EXPOSE 8001
ENTRYPOINT ["/usr/local/bin/cloudtunes-entrypoint"]
