#!/usr/bin/env bash

#DEFINE US!
ICES_BACKGROUND=`xmllint --xpath '//ices/background/text()' default0.xml`
ICES_LOGPATH=`xmllint --xpath '//ices/logpath/text()' default0.xml`
ICES_LOGFILE=`xmllint --xpath '//ices/logpath/text()' default0.xml`
ICES_LOGLEVEL=`xmllint --xpath '//ices/logfile/text()' default0.xml`
ICES_CONSOLE=`xmllint --xpath '//ices/consolelog/text()' default0.xml`

ICES_STREAM_METADATA_NAME=`xmllint --xpath '//ices/stream/metadata/name/text()' default0.xml`
ICES_STREAM_METADATA_GENRE=`xmllint --xpath '//ices/stream/metadata/name/text()' default0.xml`
ICES_STREAM_METADATA_DESCRIBE=`xmllint --xpath '//ices/stream/metadata/description/text()' default0.xml`
ICES_STREAM_INPUT_MODULE=`xmllint --xpath '//ices/stream/input/module/text()' default0.xml`
#ICES_STREAM_INPUT_MODULE_TYPE=`xmllint --xpath '//ices/stream/input/module/@type' default0.xml`
#ICES_STREAM_INPUT_MODULE_TYPE=`xmllint --xpath 'string(//ices/stream/input/module[@name="type"]/text()' default0.xml`
ICES_STREAM_INPUT_MODULE_TYPE=`xmlstarlet sel -T -t -m /ices/stream/input/module/@name -v "type" -n default0.xml`

#xmlstarlet sel -T -t -m /solr/cores/core/@name -v . -n solr.xml

printf "You are listening to ${ICES_STREAM_METADATA_NAME}: ${ICES_STREAM_METADATA_DESCRIBE}\n"
printf "You are using module ${ICES_STREAM_INPUT_MODULE} which is of type ${ICES_STREAM_INPUT_MODULE_TYPE}\n"
exit 0
#### XML ####
        <input>
            <module>playlist</module>
            <param name="type">basic</param>
            <param name="file">/etc/ices/playlist/default0.txt</param>
            <param name="random">1</param>
            <param name="restart-after-reread">0</param>
            <param name="once">0</param>
        </input>
        <instance>
            <hostname>icecast-srv</hostname>
            <port>8000</port>
            <password>dr!pepper83</password>
            <mount>/default0.ogg</mount>
            <reconnectdelay>2</reconnectdelay>
            <reconnectattempts>5</reconnectattempts> 
            <maxqueuelength>80</maxqueuelength>
            <encode>  
                <nominal-bitrate>128000</nominal-bitrate>
                <samplerate>44100</samplerate>
                <channels>2</channels>
            </encode>
        </instance>

	</stream>
</ices>
