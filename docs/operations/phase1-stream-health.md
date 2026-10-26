# Phase 1 — Stream health smoke check

## Purpose

Run a bounded smoke check against the active CloudTunes single-station deployment. It verifies two independent conditions:

1. Icecast reports the expected source mount in its status JSON.
2. The stream endpoint returns audio bytes during a short sampling window.

A successful check is stronger than an HTTP status response alone, but it does not prove that a listener can decode the stream or that audio sounds correct. Follow it with an actual playback test during acceptance testing.

## Usage

Requirements: Bash, curl, and jq. Run from a host that can reach the Icecast endpoints.

```bash
ICECAST_STATUS_URL=http://localhost:8400/status-json.xsl \
ICECAST_STREAM_URL=http://localhost:8400/default0.ogg \
bash scripts/check-stream.sh
```

Adjust the host and port to match the deployment. Defaults target Icecast at `localhost:8000`.

Optional settings:

- `EXPECTED_ICECAST_MOUNT`: expected mount path (default `/default0.ogg`).
- `MIN_STREAM_BYTES`: minimum bytes received to pass (default `4096`).
- `STREAM_CHECK_SECONDS`: maximum sampling window in seconds (default `8`).

The script intentionally bounds the stream request. Curl exit code 28 is treated as the expected sampling timeout only when enough bytes were received. Other curl failures fail the check. No stream data is saved to disk, and no credentials are printed.

## Interpretation

- **Pass:** the expected source appears in Icecast status and the endpoint delivers at least the configured byte threshold.
- **Source missing:** inspect Ices logs, source authentication, and the effective Ices/Icecast configuration.
- **Bytes missing or insufficient:** check the public stream URL, mount, network reachability, and whether the source is actually delivering audio.
- **Pass but playback fails:** test with a real audio client/browser. This script does not decode audio or test listener compatibility.

## Safety

This is a read-only smoke test. It does not restart services, alter configuration, or touch persistent volumes. Do not use it as a substitute for a backup, a restore drill, or a continuous playback test.
