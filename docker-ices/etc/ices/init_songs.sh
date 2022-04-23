#!/usr/bin/env bash

#function errorHandler() {
#}

#Check files for spaces and replace with underscores
find /data/station_0 -type f -name "* *" | while read file; do mv "$file" ${file// /_}; done

#Check for WAV, MP3, AIFF, AAC, WMA, FLAC - Convert to OGG
find /data/station_0 -type f \( -name "*.wav" -o -name "*.mp3" -o -name "*.aiff" -o -name "*.aac" -o -name "*.wma" -o -name "*.flac" \) | while read file; do ffmpeg -i $file -b:a 128k "${file%.*}.ogg"; done
