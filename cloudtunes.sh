#!/usr/bin/env bash

# Docker registry address and port
REGISTRY_HOST="localhost"
REGISTRY_PORT="5000"

# Uncomment if you do not want CloudTunes Docker to build from cache
#DOCKER_NO_CACHE="--no-cache"

# Leave this alone unless you know what you are doing.
K8S_CONTEXT="minikube"

# Namespace and option to launch after builds
LAUNCH_NAMESPACE="radio"
LAUNCH_AFTER_BUILD="false"

# CloudTunes version
CTVERSION="v0.0.55"

# Check if all requirements for CloudTunes are met
command -v docker >/dev/null 2>&1 || { echo >&2 "I require docker but it's not installed.  Aborting."; exit 1; }
command -v kubectl >/dev/null 2>&1 || { echo >&2 "I require kubtctl but it's not installed.  Aborting."; exit 1; }
command -v kubectx >/dev/null 2>&1 || { echo >&2 "I require kubectx but it's not installed.  Aborting."; exit 1; }
command -v kubens >/dev/null 2>&1 || { echo >&2 "I require kubens but it's not installed.  Aborting."; exit 1; }
command -v mpv >/dev/null 2>&1 || { echo >&2 "I optionally require mpv but it's not installed."; }
command -v vis >/dev/null 2>&1 || { echo >&2 "I optionally require cli-visualizer but it's not installed."; }

# CloudTunes begins here
function version() {
	printf "CloudTunes ${CTVERSION}\n"
}

# Check environment
function check_env() {
	[ -z ${DOCKER_HOST} ] && { printf "To use your local docker environment, use the --use-sysdocker flag!\nTo use the minikube docker environment, use 'eval \$(minikube docker-env)'\n"; exit 1; }
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

while test $# -gt 0; do
		case "$1" in
			-h|--help)
					version
					echo "options:"
					echo "-h, --help		Its what youre looking at!"
					echo "-b, --build		(Re)build CloudTunes docker images"
					echo "-B, --browser		Launch CloudTunes service in browser"
					echo "-l, --launch		Launch CloudTunes"
					echo "-d, --destroy		Destroy CloudTunes"
					echo "-F[B], --full		Destroy, rebuild, then relaunch CloudTunes, and optionally browser"
					echo "-L, --listen		Launch radio station in MPV"
					echo "-Lg			Launch radio station in MPV with FFmpeg visuals"
					echo "-Lc			Launch radio station in MPV with cli-visualizer (https://github.com/dpayne/cli-visualizer)"
					echo "-S, --use-sysdocker	Bypass enviornment checking"
					echo "-v, --version		Show version"
					exit 0
					;;
			-S|--use-sysdocker)
					ENV_SKIP=1
					shift
					;;
			-b|--build)
					[[ ${ENV_SKIP} == "1" ]] || check_env
					createNS
					switchNS
					for MDIR in `ls -d */`; do
						docker build ${DOCKER_NO_CACHE} -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
						docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
					done
					shift
					;;
			-B|--browser)
					minikube service -n radio radio-fe-app
					exit 0
					;;
			-L|--listen)
					mpv $(minikube service -n radio icecast-srv --url)/default0.ogg
					exit 0
					;;
			-Lg)
					mpv --lavfi-complex='[aid1] asplit [ao] [v] ; [v] showwaves=mode=p2p:split_channels=1,format=rgb0 [vo]' $(minikube service -n radio icecast-srv --url)/default0.ogg
					exit 0
					;;
			-Lc)		mpv --really-quiet $(minikube service -n radio icecast-srv --url)/default0.ogg | vis
					exit 0
					;;
			-l|--launch)
					[[ $ENV_SKIP = "1" ]] || check_env
					switchNS
					for MDIR in `ls -d */`; do
						kubectl create -f ${MDIR}/deploy/
					done
					exit 0
					;;
			-d|--destroy)
					[[ $ENV_SKIP = "1" ]] || check_env
					switchNS
					for MDIR in `ls -d */`; do
						kubectl delete -f ${MDIR}/deploy/
					done
					exit 0
					;;
			-F|-FB|--full)
					[[ $ENV_SKIP = "1" ]] || check_env
					createNS
					switchNS
					for MDIR in `ls -d */`; do
                                                kubectl delete -f ${MDIR}/deploy
                                        	docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
                                        	docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
                                        	kubectl create -f ${MDIR}/deploy/
                                        done
                                        kubectl get po
                                        if [[ "$1" == "-FB" ]]; then
                                        	printf "Waiting a few seconds...\n"
                                        	sleep 5
                                        	minikube service -n radio radio-fe-app
                                        fi
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
