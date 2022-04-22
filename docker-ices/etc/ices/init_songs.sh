#!/usr/bin/env bash

#function errorHandler() {
#}

printf "Checking song filenames...\n"

for file in /data/station_0/*.ogg;                                                                                                                                                                       ✔  16:10:50  
do
echo Converting "$file" to "${file// /_}"
mv "$file" "${file// /_}"
done
