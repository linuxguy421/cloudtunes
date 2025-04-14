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
ADD data/ /data
RUN /etc/ices/init_songs.sh
RUN /etc/ices/update_songs.sh
RUN /etc/ices/process_songs.sh

# Add supervisord conf, bootstrap.sh files
COPY container-files /
COPY supervisord-ices.conf /etc/supervisor.d/supervisord-ices.conf

# Expose ports for radio station
EXPOSE 8001
ENTRYPOINT ["/config/bootstrap.sh"]
