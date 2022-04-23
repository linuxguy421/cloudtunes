# CloudTune

### NOTICE: This is in early development and works with Minikube -- you'll
### need to modify things (such as IP addresses) to work directly with your Docker/Kubernetes

### Installation

#### Easy Method 

The easy way is to use the included buildRadio.sh script, see how to use it with:

```./buildRadio.sh -h```

To build the entire project and launch it:

```./buildRadio.sh -F```

#### Manual Method

To build the cloudtune images from the Dockerfiles, run the commands:

```docker build -t icecast:latest ./docker-icecast/```

```docker build -t ices:latest ./docker-ices/```

```docker build -t radio-frontend:latest ./docker-frontend/```

The generated docker images can be run with the commands:

```docker run -d -p 8000:8000 icecast:latest```

```docker run -d -p 8001:8001 ices:latest```

```docker run -d -p 80:80 -p 443:443 radio-frontend:latest```

You can deploy the images to Kubernetes with the command:

```kubectl create -f docker-icecast/deploy -f docker-ices/deploy -f docker-frontend/deploy```

**NOTES**

- Early development limitations
  - ~~OGG~~ Audio files must be placed in docker-ices/data/station_0 folder
  - Filenames of ~~OGG~~ audio files MUST be in ARTIST - TITLE format for 'Now Playing' to work properly
  - ~~You must use underscores instead of spaces in OGG filenames (script below)~~ Now with automatic processing!

**TODO**

- Create PVC for OGG files
- Modify front-end to add OGG files
- Modify front-end to allow playlist reloads
- Modify front-end to allow multiple stations to be displayed
- Modify configuration files to act as templates
- Modify configuration files to allow multiple stations per icecast server

**SCREENSHOT**

![Alt text](https://cloudtunes.us/cloudtunes-ss.png)
