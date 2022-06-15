#!/usr/bin/env bash

REGISTRY_HOST="localhost"
REGISTRY_PORT="5000"
K8S_CONTEXT="minikube"
CTVERSION="v0.0.54"
GUI_MODE="dialog" # dialog or zenity

command -v docker >/dev/null 2>&1 || { echo >&2 "I require docker but it's not installed.  Aborting."; exit 1; }
command -v kubectl >/dev/null 2>&1 || { echo >&2 "I require kubtctl but it's not installed.  Aborting."; exit 1; }
command -v kubectx >/dev/null 2>&1 || { echo >&2 "I require kubectx but it's not installed.  Aborting."; exit 1; }
command -v kubens >/dev/null 2>&1 || { echo >&2 "I require kubens but it's not installed.  Aborting."; exit 1; }
command -v zenity >/dev/null 2>&1 || ( echo >&2 "I require zenity but it's not installed.  Aborting."; exit 1; )
command -v dialog >/dev/null 2>&1 || ( echo >&2 "I require dialog but it's not installed.  Aborting."; exit 1; )

LAUNCH_NAMESPACE="radio"
LAUNCH_AFTER_BUILD="false"

function version() {
	if [ "${GUI_MODE}" == "dialog" ]; then
		printf "${GUI_MODE}\n";
	else
		printf "${GUI_MODE}\n";
	fi
}

function check_env() {
	setDocker=`eval $(docker-machine env)`
	setMinikube=`eval $(minikube docker-env)`
	[ -z ${DOCKER_HOST} ] && { result$(dialog --title "No Environment" --radiolist \
	"Select environment" 15 60 4 \
	"1" "Docker" OFF \
	"2" "Minikube" ON); }  
	case $result in
		1 ) 
			 trap ${setDocker} EXIT
			 ;;
		2 )
			 trap ${setMinikube} EXIT
			 ;;
	esac
}

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
					echo "-F, --full		Destroy, rebuild, then relaunch CloudTunes"
					echo "--use-sysdocker		Bypass enviornment checking"
					echo "-v, --version		Show version"
					exit 0
					;;
			--use-sysdocker)
					ENV_SKIP=1
					shift
					;;
			-b|--build)
					[[ ${ENV_SKIP} == "1" ]] || check_env
					createNS
					switchNS
					for MDIR in `ls -d */`; do
						docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
						docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
					done
					shift
					;;
			-B|--browser)
					minikube service -n radio radio-fe-app
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
			-F|--full)
					[[ $ENV_SKIP = "1" ]] || check_env
					switchNS
					for MDIR in `ls -d */`; do
                                                kubectl delete -f ${MDIR}/deploy
                                        	docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
                                        	docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
                                        	kubectl create -f ${MDIR}/deploy/
                                        done
                                        minikube service -n radio --all
                                        kubectl get po
                                        exit 0
                                        ;;
			-v|--version)
					version
					exit 0
					;;
			*)
					break
					;;
					esac
done

# Start Main Menu
DIALOG_CANCEL=1
DIALOG_ESC=255
HEIGHT=0
WIDTH=0

display_result() {
  dialog --title "$1" \
    --no-collapse \
    --msgbox "$result" 0 0
}

while true; do
  exec 3>&1
  selection=$(dialog \
    --backtitle "CloudTunes Administration" \
    --title "Main Menu" \
    --clear \
    --cancel-label "Exit" \
    --menu "Select Option:" $HEIGHT $WIDTH 4 \
    "1" "Build CloudTunes in Docker/Kubernetes" \
    "2" "Launch CloudTunes Kubernetes Resources" \
    "3" "Destroy CloudTunes Kubernetes Resources" \
    "4" "Display CloudTunes version" \
    2>&1 1>&3)
  exit_status=$?
  exec 3>&-
  case $exit_status in
    $DIALOG_CANCEL)
      clear
      echo "Program terminated."
      exit
      ;;
    $DIALOG_ESC)
      clear
      echo "Program aborted." >&2
      exit 1
      ;;
  esac
  case $selection in
    1 )
      [[ ${ENV_SKIP} == "1" ]] || check_env
			createNS
			switchNS
			for MDIR in `ls -d */`; do
				result=$(docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR} && docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest)
				#docker build -t ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest ./${MDIR}
				#docker push ${REGISTRY_HOST}:${REGISTRY_PORT}/${MDIR%/}:latest
			done
      display_result "Building CloudTunes"
      ;;
    2 )
      result=$(df -h)
      display_result "Disk Space"
      ;;
    3 )
      if [[ $(id -u) -eq 0 ]]; then
        result=$(du -sh /home/* 2> /dev/null)
        display_result "Home Space Utilization (All Users)"
      else
        result=$(du -sh $HOME 2> /dev/null)
        display_result "Home Space Utilization ($USER)"
      fi
      ;;
  esac
done

# End Main Menu
exit ${exit_status}

