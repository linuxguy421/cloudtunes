FROM alpine:latest

COPY boot.sh /boot.sh
RUN apk --no-cache add memcached && chmod +x /boot.sh

USER memcached
CMD ["/boot.sh"]
