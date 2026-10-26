#!/usr/bin/env bash
set -Eeuo pipefail

# Bounded smoke check for the currently configured Icecast station.
# This verifies source visibility and that audio bytes arrive; it does not
# replace a real listening/client decode test.

STATUS_URL="${ICECAST_STATUS_URL:-http://localhost:8000/status-json.xsl}"
STREAM_URL="${ICECAST_STREAM_URL:-http://localhost:8000/default0.ogg}"
EXPECTED_MOUNT="${EXPECTED_ICECAST_MOUNT:-/default0.ogg}"
MIN_BYTES="${MIN_STREAM_BYTES:-4096}"
STREAM_TIMEOUT="${STREAM_CHECK_SECONDS:-8}"

for command_name in curl jq; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'ERROR: required command not found: %s\n' "$command_name" >&2
    exit 2
  fi
done

if ! [[ "$MIN_BYTES" =~ ^[0-9]+$ ]] || (( MIN_BYTES < 1 )); then
  echo "ERROR: MIN_STREAM_BYTES must be a positive integer." >&2
  exit 2
fi
if ! [[ "$STREAM_TIMEOUT" =~ ^[0-9]+$ ]] || (( STREAM_TIMEOUT < 1 )); then
  echo "ERROR: STREAM_CHECK_SECONDS must be a positive integer." >&2
  exit 2
fi

printf 'Checking Icecast status endpoint... '
status_json="$(curl --fail --silent --show-error --max-time 5 "$STATUS_URL")" || {
  echo "FAILED"
  echo "ERROR: Icecast status endpoint did not return a successful response." >&2
  exit 1
}
echo "OK"

if ! jq -e --arg mount "$EXPECTED_MOUNT" '
  [.icestats.source] | flatten |
  any(.[]; ((.listenurl // "") | endswith($mount)))
' >/dev/null <<<"$status_json"; then
  echo "ERROR: expected source mount is not reported by Icecast: $EXPECTED_MOUNT" >&2
  exit 1
fi
printf 'Expected source mount %s is present.\n' "$EXPECTED_MOUNT"

# A live stream normally remains open. curl's timeout exit code (28) is
# expected here if enough bytes arrived before the bounded sampling window.
set +e
downloaded_bytes="$(curl --fail --silent --show-error \
  --max-time "$STREAM_TIMEOUT" \
  --output /dev/null --write-out '%{size_download}' "$STREAM_URL" 2>/dev/null)"
curl_rc=$?
set -e

if [[ ! "$downloaded_bytes" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
  echo "ERROR: could not measure bytes from the stream endpoint." >&2
  exit 1
fi
bytes_integer="${downloaded_bytes%%.*}"

if (( curl_rc != 0 && curl_rc != 28 )); then
  printf 'ERROR: stream request failed (curl exit %s).\n' "$curl_rc" >&2
  exit 1
fi
if (( bytes_integer < MIN_BYTES )); then
  printf 'ERROR: received %s bytes; expected at least %s.\n' "$bytes_integer" "$MIN_BYTES" >&2
  exit 1
fi

printf 'Stream delivered %s bytes within %s seconds.\n' "$bytes_integer" "$STREAM_TIMEOUT"
echo "PASS: source is visible and stream bytes are flowing."
