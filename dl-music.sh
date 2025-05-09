#!/usr/bin/env bash

# A simple script to make downloading video files and converting to OGG for use with CloudTunes

# Set default destination path (be careful changing this)
DEFAULT_PATH="docker-ices/data/station_0"

# Check if all requirements are met
command -v yt-dlp >/dev/null 2>&1 || { echo >&2 "I require yt-dlp but it's not installed.  Aborting."; exit 1; }

function doDownload {
	yt-dlp --audio-format vorbis --extract-audio --audio-quality 5 $plmode $maxfiles -o "$DEFAULT_PATH/%(title)s.%(ext)s" "$1"
	#--playlist-items 1:$MAXFILES
}

while test $# -gt 0; do
		case "$1" in
			-h|--help)
					echo "options:"
					echo "-h, --help		Its what youre looking at!"
					echo "pl, playlist [URL]	Download a playlist and convert to OGG files"
					echo "-m [number]		When in playlist mode, only download # files"
					echo "dl, download [URL]	Download a single video and convert to OGG"
					echo "wipe			Wipe all OGG files from $DEFAULT_PATH"
					exit 0
					;;
			wipe)
					if [ -z "$( ls -A $DEFAULT_PATH )" ]; then echo "Can't wipe what isn't there"; else rm -v $DEFAULT_PATH/*.ogg; fi
					exit 0
					;;
			pl|playlist)
					export plmode="--yes-playlist"
					export maxfiles="--playlist-items 1:$4"
					doDownload $2 $4
					exit 0
					;;
			dl|download)
					#if [ -z "$2" ]; then echo "You should include what you want to download"; fi; exit 1
					doDownload $2
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
