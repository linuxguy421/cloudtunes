# CloudTunes Helm Chart

This Helm chart deploys the **CloudTunes** streaming stack to Kubernetes, including:

- **Icecast** (streaming server)
- **Ices** (per-station source clients)
- **Frontend** (web UI)
- **Memcached** (caching layer)

It is designed for both **hobby use** and **production-ready deployments**.  
Stations are defined declaratively in `values.yaml` and mounted into pods using ConfigMaps.

---

## Prerequisites

- Kubernetes cluster (local [kind/minikube] or production)
- [Helm 3.x](https://helm.sh/docs/intro/install/)
- Docker images for:
  - Icecast
  - Ices
  - Frontend
  - (Memcached uses the official image)

---

## Quick Start

1. **Clone this repository**
   ```bash
   git clone https://github.com/linuxguy421/cloudtunes.git
   cd cloudtunes/charts/cloudtunes
   ```

2. **Inspect `values.yaml`**
   ```yaml
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
   ```

   Each entry under `stations:` defines a new radio station.

3. **Install the chart**
   ```bash
   helm install myradio .
   ```

4. **Verify resources**
   ```bash
   kubectl get pods
   kubectl get svc
   ```

   You should see pods for:
   - Icecast (`myradio-icecast`)
   - Ices stations (`myradio-ices-station0`, etc.)
   - Frontend
   - Memcached

5. **Access the frontend**
   - Expose the frontend service (via `kubectl port-forward` or LoadBalancer/Ingress).
   - Default port is `80`.

   ```bash
   kubectl port-forward svc/myradio-frontend 8080:80
   ```

   Visit: [http://localhost:8080](http://localhost:8080)

---

## Managing Stations

### Add a new station
1. Edit `values.yaml` and append a new entry under `ices.stations`:
   ```yaml
   - name: trance
     mount: /trance
     playlist: |
       /music/trance1.ogg
       /music/trance2.ogg
     config: |
       <ices>
         <stream>
           <mount>/trance</mount>
           <bitrate>192</bitrate>
         </stream>
       </ices>
   ```

2. Upgrade the release:
   ```bash
   helm upgrade myradio .
   ```

### Update playlist or config
- Modify the `playlist:` or `config:` sections for the station.
- Apply changes:
  ```bash
  helm upgrade myradio .
  ```
- The relevant Ices pod will restart with the updated ConfigMap.

### Remove a station
- Delete its entry from `values.yaml`.
- Apply changes:
  ```bash
  helm upgrade myradio .
  ```

---

## Passwords & Secrets

Icecast admin, source, and relay passwords are stored in a **Kubernetes Secret**.  
Defaults are defined in `values.yaml`:

```yaml
icecast:
  adminPassword: "admin123"
  sourcePassword: "source123"
  relayPassword: "relay123"
```

Override them at install time:

```bash
helm install myradio .   --set icecast.adminPassword=supersecret   --set icecast.sourcePassword=streamkey   --set icecast.relayPassword=relaykey
```

---

## Notes

- **Local dev**: Use `minikube service myradio-icecast --url` to access Icecast directly.
- **Production**: Deploy behind an Ingress controller (NGINX, Traefik, etc.).
- **Scaling frontend**: Adjust `frontend.replicas` in `values.yaml`.

---

## Cleanup

To uninstall the deployment:

```bash
helm uninstall myradio
```

This removes all deployed resources (Deployments, Services, ConfigMaps, Secrets).
