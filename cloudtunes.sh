#!/usr/bin/env bash

# Host IP (Try the internal host IP)
HOSTIP=`ip route get 1 | awk '{print $7}'`

# Docker registry address and port
REGISTRY_HOST="localhost"
REGISTRY_PORT="5000"

# Uncomment if you do not want CloudTunes Docker to build from cache
#DOCKER_NO_CACHE="--no-cache"

# Leave this alone unless you know what you are doing.
K8S_CONTEXT="default"

# Namespace and option to launch after builds
LAUNCH_NAMESPACE="radio"
LAUNCH_AFTER_BUILD="false"

# CloudTunes version
CTVERSION="v0.0.99"

# Check if all requirements for CloudTunes are met
command -v docker >/dev/null 2>&1 || { echo >&2 "I require docker but it's not installed.  Aborting."; exit 1; }
command -v kubectl >/dev/null 2>&1 || { echo >&2 "I require kubtctl but it's not installed.  Aborting."; exit 1; }
command -v kubectx >/dev/null 2>&1 || { echo >&2 "I require kubectx but it's not installed.  Aborting."; exit 1; }
command -v kubens >/dev/null 2>&1 || { echo >&2 "I require kubens but it's not installed.  Aborting."; exit 1; }
command -v k3s >/dev/null 2>&1 || { echo >&2 "This was built for k3s, while I won't require it I thought you'd like to know it's not installed."; exit 1; }
#command -v docker-buildx >/dev/null 2>&1 || { echo >&2 "I require docker-buildx but it's not installed.  Aborting."; exit 1; }
command -v mpv >/dev/null 2>&1 || { echo >&2 "I optionally require mpv but it's not installed."; }
command -v vis >/dev/null 2>&1 || { echo >&2 "I optionally require cli-visualizer but it's not installed."; }

# CloudTunes begins here
function version() {
	printf "CloudTunes ${CTVERSION}\n"
}

# Switch namespace
function switchNS {
	kubectx ${K8S_CONTEXT}
	kubens ${LAUNCH_NAMESPACE}
}

# Create namespace
function createNS {
	kubectl create ns ${LAUNCH_NAMESPACE} && switchNS
}

function updateIP {
	find . -name '*.php' -type f -exec sed -i -E "s/([0-9]{1,3}\.){3}[0-9]{1,3}/${HOSTIP}"/ {} \;
	printf "✔ Updated IP address to "${HOSTIP}"\n"
}

function getPorts {
	FE_PORT=$(kubectl get service/radio-fe-app -o jsonpath="{.spec.ports[*].nodePort}")
	ICECASTSRV_PORT=$(kubectl get service/icecast-srv -o jsonpath="{.spec.ports[*].nodePort}")
	ICESSRV_PORT=$(kubectl get service/ices-station -o jsonpath="{.spec.ports[*].nodePort}")
}

waitPods(){
	TIMETOWIPE=10
	printf "╒═════════════════════╕\n"
	printf "│ Waiting for pods... │\n"
	while [ ${TIMETOWIPE} -gt -1 ]; do
	TIMETOWIPE_PAD=$(printf "%02d" ${TIMETOWIPE})
	echo -ne ""╘═[$TIMETOWIPE_PAD]════════════════╛"\033[0K\r"
	[ ${TIMETOWIPE} -eq 0 ] && printf "\n"
	sleep 1
	: $((TIMETOWIPE--))
	done
}

while test $# -gt 0; do
		case "$1" in
			-h|--help)
					version
					echo "options:"
					echo "-h, --help		Its what youre looking at!"
					echo "-b, --build		(Re)build CloudTunes docker images"
					echo "-l, --launch		Launch CloudTunes"
					echo "-d, --destroy		Destroy CloudTunes"
					echo "-F, --full		Destroy, rebuild, then relaunch CloudTunes"
					echo "-L, --listen		Launch radio station in MPV"
					echo "-Lg			Launch radio station in MPV with FFmpeg visuals"
					echo "-Lc			Launch radio station in MPV with cli-visualizer (https://github.com/dpayne/cli-visualizer)"
					echo "-v, --version		Show version"
					exit 0
					;;
			-b|--build)
					createNS
					switchNS
					for MDIR in `ls -d */`; do
						docker build ${DOCKER_NO_CACHE} -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
						docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
					done
					shift
					;;
			-L|--listen)
					getPorts
					mpv http://${HOSTIP}:${ICECASTSRV_PORT}/default0.ogg
					exit 0
					;;
			-Lg)
					getPorts
					mpv --lavfi-complex='[aid1] asplit [ao] [v] ; [v] showwaves=mode=p2p:split_channels=1,format=rgb0 [vo]' http://${HOSTIP}:${ICECASTSRV_PORT}/default0.ogg
					exit 0
					;;
			-Lc)		
					getPorts
					mpv --really-quiet http://${HOSTIP}:${ICECASTSRV_PORT}/default0.ogg | vis
					exit 0
					;;
			-l|--launch)
					switchNS
					updateIP
					for MDIR in `ls -d */`; do
						kubectl create -f ${MDIR}/deploy/
					done
					getPorts
					waitPods
					printf "╒════════════════════════════════════════════════════════════╕\n"
                                        printf "│ Frontend: ${HOSTIP}:${FE_PORT}                                │\n"
                                        printf "│ Icecast Server:  ${HOSTIP}:${ICECASTSRV_PORT}                         │\n"
                                        printf "│ Station Stream:  ${HOSTIP}:${ICESSRV_PORT}                         │\n"
                                        printf "╘════════════════════════════════════════════════════════════╛\n"
					exit 0
					;;
			-d|--destroy)
					switchNS
					for MDIR in `ls -d */`; do
						kubectl delete -f ${MDIR}/deploy/
					done
					exit 0
					;;
			-F|--full)
					createNS
					switchNS
					for MDIR in `ls -d */`; do
                                                kubectl delete -f ${MDIR}/deploy
                                        	docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
                                        	docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
                                        	kubectl create -f ${MDIR}/deploy/
                                        done
                                        kubectl get po
                                        exit 0
                                        ;;
			-v|--version)
					version
					exit 0
					;;
			*)
					printf "I dont know how to do that.\n"
					break
					;;
					esac
done
if [ -z "$1" ]; then version; fi
exit 0
