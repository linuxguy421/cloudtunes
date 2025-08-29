#!/usr/bin/env bash

# cloudtunes-helm-init.sh
# Script to scaffold a Helm chart for CloudTunes

set -euo pipefail

CHART_NAME="cloudtunes"
CHART_DIR="charts/$CHART_NAME"

# Create chart directory structure
mkdir -p "$CHART_DIR/templates"

# Chart.yaml
cat > "$CHART_DIR/Chart.yaml" <<'EOF'
apiVersion: v2
name: cloudtunes
description: A Helm chart for deploying CloudTunes (Icecast, Ices stations, frontend, memcached)
type: application
version: 0.1.0
appVersion: "1.0"
EOF

# values.yaml
cat > "$CHART_DIR/values.yaml" <<'EOF'
icecast:
  image: your-registry/icecast:latest
  adminPassword: "changeme"
  sourcePassword: "changeme"
  relayPassword: "changeme"
  ports:
    http: 8000

ices:
  image: your-registry/ices:latest
  stations:
    - name: station0
      mount: /stream0
      playlist: |
        # playlist0.txt
        /music/song1.ogg
        /music/song2.ogg
      config: |
        <ices>
          <stream>
            <mount>/stream0</mount>
            <bitrate>128</bitrate>
          </stream>
        </ices>
    - name: station1
      mount: /stream1
      playlist: |
        # playlist1.txt
        /music/song3.ogg
        /music/song4.ogg
      config: |
        <ices>
          <stream>
            <mount>/stream1</mount>
            <bitrate>192</bitrate>
          </stream>
        </ices>

frontend:
  image: your-registry/frontend:latest
  replicas: 2
  servicePort: 80

memcached:
  image: memcached:1.6
  replicas: 1
EOF

# templates/icecast-deployment.yaml
cat > "$CHART_DIR/templates/icecast-deployment.yaml" <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ .Release.Name }}-icecast
spec:
  replicas: 1
  selector:
    matchLabels:
      app: icecast
  template:
    metadata:
      labels:
        app: icecast
    spec:
      containers:
        - name: icecast
          image: {{ .Values.icecast.image }}
          ports:
            - containerPort: {{ .Values.icecast.ports.http }}
          env:
            - name: ICECAST_ADMIN_PASSWORD
              value: {{ .Values.icecast.adminPassword | quote }}
            - name: ICECAST_SOURCE_PASSWORD
              value: {{ .Values.icecast.sourcePassword | quote }}
            - name: ICECAST_RELAY_PASSWORD
              value: {{ .Values.icecast.relayPassword | quote }}
EOF

# templates/icecast-service.yaml
cat > "$CHART_DIR/templates/icecast-service.yaml" <<'EOF'
apiVersion: v1
kind: Service
metadata:
  name: {{ .Release.Name }}-icecast
spec:
  type: ClusterIP
  ports:
    - port: {{ .Values.icecast.ports.http }}
      targetPort: {{ .Values.icecast.ports.http }}
      protocol: TCP
      name: http
  selector:
    app: icecast
EOF

# templates/ices-deployment.yaml
cat > "$CHART_DIR/templates/ices-deployment.yaml" <<'EOF'
{{- range .Values.ices.stations }}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $.Release.Name }}-ices-{{ .name }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ices
      station: {{ .name }}
  template:
    metadata:
      labels:
        app: ices
        station: {{ .name }}
    spec:
      containers:
        - name: ices
          image: {{ $.Values.ices.image }}
          volumeMounts:
            - name: playlist
              mountPath: /ices/playlist
              subPath: playlist.txt
            - name: config
              mountPath: /ices/config
              subPath: config.xml
      volumes:
        - name: playlist
          configMap:
            name: {{ $.Release.Name }}-ices-{{ .name }}-playlist
        - name: config
          configMap:
            name: {{ $.Release.Name }}-ices-{{ .name }}-config
---
{{- end }}
EOF

# templates/ices-configmap.yaml
cat > "$CHART_DIR/templates/ices-configmap.yaml" <<'EOF'
{{- range .Values.ices.stations }}
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $.Release.Name }}-ices-{{ .name }}-playlist
data:
  playlist.txt: |
{{ .playlist | indent 4 }}
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $.Release.Name }}-ices-{{ .name }}-config
data:
  config.xml: |
{{ .config | indent 4 }}
---
{{- end }}
EOF

# templates/frontend-deployment.yaml
cat > "$CHART_DIR/templates/frontend-deployment.yaml" <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ .Release.Name }}-frontend
spec:
  replicas: {{ .Values.frontend.replicas }}
  selector:
    matchLabels:
      app: frontend
  template:
    metadata:
      labels:
        app: frontend
    spec:
      containers:
        - name: frontend
          image: {{ .Values.frontend.image }}
          ports:
            - containerPort: {{ .Values.frontend.servicePort }}
EOF

# templates/frontend-service.yaml
cat > "$CHART_DIR/templates/frontend-service.yaml" <<'EOF'
apiVersion: v1
kind: Service
metadata:
  name: {{ .Release.Name }}-frontend
spec:
  type: ClusterIP
  ports:
    - port: {{ .Values.frontend.servicePort }}
      targetPort: {{ .Values.frontend.servicePort }}
      protocol: TCP
      name: http
  selector:
    app: frontend
EOF

# templates/memcached-deployment.yaml
cat > "$CHART_DIR/templates/memcached-deployment.yaml" <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ .Release.Name }}-memcached
spec:
  replicas: {{ .Values.memcached.replicas }}
  selector:
    matchLabels:
      app: memcached
  template:
    metadata:
      labels:
        app: memcached
    spec:
      containers:
        - name: memcached
          image: {{ .Values.memcached.image }}
          args: ["-m", "64", "-p", "11211"]
EOF

# templates/memcached-service.yaml
cat > "$CHART_DIR/templates/memcached-service.yaml" <<'EOF'
apiVersion: v1
kind: Service
metadata:
  name: {{ .Release.Name }}-memcached
spec:
  type: ClusterIP
  ports:
    - port: 11211
      targetPort: 11211
      protocol: TCP
      name: memcached
  selector:
    app: memcached
EOF

echo "✅ Helm chart for $CHART_NAME created at $CHART_DIR with ConfigMaps for playlists/configs"
