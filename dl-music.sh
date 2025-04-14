#!/usr/bin/env bash

MAXFILES=10

# Check if all requirements for CloudTunes are met
command -v yt-dlp >/dev/null 2>&1 || { echo >&2 "I require yt-dlp but it's not installed.  Aborting."; exit 1; }

function updateIP {
	printf "Updating IP address...\n"
	find . -name '*.php' -type f -exec sed -i -E "s/([0-9]{1,3}\.){3}[0-9]{1,3}/`minikube ip`/" {} \;
}

while test $# -gt 0; do
		case "$1" in
			-h|--help)
					echo "options:"
					echo "-h, --help		Its what youre looking at!"
					echo "-d, download, dl		Download videos and convert to OGG files"
					exit 0
					;;
			-d|download|dl)
					#if [ -z "$2" ]; then echo "You should include what you want to download"; fi; exit 1
					echo "Downloading $2...\n"
					yt-dlp --audio-format vorbis --extract-audio --audio-quality 5 --yes-playlist --playlist-items 1:$MAXFILES -o 'docker-ices/data/station0/%(title)s.%(ext)s' "$2"
					exit 0
					;;
			*)
					printf "I dont know how to do that.\n"
					break
					;;
					esac
done
if [ -z "$1" ]; then echo "huh?"; fi
exit 0
