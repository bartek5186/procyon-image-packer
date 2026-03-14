#!/bin/sh

set -u

STATE_ROOT="${1:-}"
if [ -z "$STATE_ROOT" ]; then
  echo "Usage: $0 /path/to/procyon-image-packer-state" >&2
  exit 1
fi

JOB_ENV="$STATE_ROOT/job.env"
if [ ! -f "$JOB_ENV" ]; then
  echo "Missing job.env in $STATE_ROOT" >&2
  exit 1
fi

# shellcheck disable=SC1090
. "$JOB_ENV"

require_env() {
  var_name="$1"
  eval "var_present=\${${var_name}+x}"
  if [ -z "${var_present:-}" ]; then
    echo "Missing required env variable: $var_name" >&2
    exit 1
  fi
}

require_env "OPTIMIZE_ORIGINALS"
require_env "GENERATE_WEBP"
require_env "GENERATE_AVIF"
require_env "MANIFEST_FILE"
require_env "RUNTIME_FILE"
require_env "DONE_FILE"
require_env "FAILED_FILE"
require_env "PAUSE_FLAG_FILE"
require_env "LOCK_FILE"

TAB="$(printf '\t')"
MANIFEST_FILE="${MANIFEST_FILE:-$STATE_ROOT/manifest.tsv}"
RUNTIME_FILE="${RUNTIME_FILE:-$STATE_ROOT/runtime.status}"
DONE_FILE="${DONE_FILE:-$STATE_ROOT/done.tsv}"
FAILED_FILE="${FAILED_FILE:-$STATE_ROOT/failed.tsv}"
PAUSE_FLAG_FILE="${PAUSE_FLAG_FILE:-$STATE_ROOT/pause.flag}"
LOCK_FILE="${LOCK_FILE:-$STATE_ROOT/runner.lock}"
PID="$$"

TOTAL=0
SUCCESS=0
FAILED=0
PROCESSED=0
CURRENT_FILE=""

line_count() {
  file_path="$1"
  if [ ! -f "$file_path" ]; then
    echo "0"
    return
  fi

  count="$(wc -l < "$file_path" 2>/dev/null | tr -d ' ')"
  if [ -z "$count" ]; then
    count="0"
  fi
  echo "$count"
}

timestamp() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

write_status() {
  status_value="$1"
  current_value="$2"
  cat > "$RUNTIME_FILE" <<EOF
status=$status_value
total=$TOTAL
processed=$PROCESSED
success=$SUCCESS
failed=$FAILED
current_file=$current_value
pid=$PID
updated_at=$(timestamp)
EOF
}

cleanup() {
  rm -f "$LOCK_FILE"
}

trap cleanup EXIT INT TERM

if [ ! -f "$MANIFEST_FILE" ]; then
  echo "Missing manifest file: $MANIFEST_FILE" >&2
  exit 1
fi

TOTAL="$(line_count "$MANIFEST_FILE")"
SUCCESS="$(line_count "$DONE_FILE")"
FAILED="$(line_count "$FAILED_FILE")"
PROCESSED=$((SUCCESS + FAILED))

printf '%s\n' "$PID" > "$LOCK_FILE"
write_status "running" ""

already_done() {
  relative_path="$1"
  signature="$2"
  if [ ! -f "$DONE_FILE" ]; then
    return 1
  fi

  needle="$(printf '%s\t%s\t' "$relative_path" "$signature")"
  grep -Fq "$needle" "$DONE_FILE"
}

already_failed() {
  relative_path="$1"
  signature="$2"
  if [ ! -f "$FAILED_FILE" ]; then
    return 1
  fi

  needle="$(printf '%s\t%s\t' "$relative_path" "$signature")"
  grep -Fq "$needle" "$FAILED_FILE"
}

already_processed() {
  relative_path="$1"
  signature="$2"

  if already_done "$relative_path" "$signature"; then
    return 0
  fi

  if already_failed "$relative_path" "$signature"; then
    return 0
  fi

  return 1
}

append_done() {
  relative_path="$1"
  signature="$2"
  attachment_id="$3"
  original_done="$4"
  webp_done="$5"
  avif_done="$6"
  source_mime="$7"

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$relative_path" \
    "$signature" \
    "$attachment_id" \
    "$original_done" \
    "$webp_done" \
    "$avif_done" \
    "$(timestamp)" \
    "$source_mime" >> "$DONE_FILE"
}

append_failed() {
  relative_path="$1"
  signature="$2"
  attachment_id="$3"
  reason="$4"

  printf '%s\t%s\t%s\t%s\t%s\n' \
    "$relative_path" \
    "$signature" \
    "$attachment_id" \
    "$reason" \
    "$(timestamp)" >> "$FAILED_FILE"
}

optimize_original() {
  source_mime="$1"
  source_path="$2"

  if [ "${OPTIMIZE_ORIGINALS:-0}" != "1" ]; then
    return 0
  fi

  case "$source_mime" in
    image/jpeg)
      if [ -z "${JPEGOPTIM:-}" ]; then
        return 0
      fi
      "$JPEGOPTIM" --strip-all --all-progressive --max="${JPEG_MAX_QUALITY:-85}" "$source_path" >/dev/null
      rc="$?"
      if [ "$rc" -ne 0 ]; then
        echo "jpegoptim failed ($rc): $source_path" >&2
      fi
      return "$rc"
      ;;
    image/png)
      if [ -z "${PNGQUANT:-}" ]; then
        return 0
      fi
      source_dir="$(dirname "$source_path")"
      source_file="$(basename "$source_path")"
      tmp_output="$source_dir/.${source_file}.procyon-${PID}.png"
      rm -f "$tmp_output"

      "$PNGQUANT" --force --skip-if-larger --strip --quality="${PNG_QUALITY_MIN:-65}-${PNG_QUALITY_MAX:-85}" --output "$tmp_output" -- "$source_path" >/dev/null
      rc="$?"
      if [ "$rc" -eq 0 ]; then
        if [ -f "$tmp_output" ]; then
          mv -f "$tmp_output" "$source_path"
          mv_rc="$?"
          if [ "$mv_rc" -ne 0 ]; then
            echo "pngquant move failed ($mv_rc): $tmp_output -> $source_path" >&2
            rm -f "$tmp_output"
            return "$mv_rc"
          fi
        fi
        return 0
      fi

      rm -f "$tmp_output"

      if [ "$rc" -eq 98 ] || [ "$rc" -eq 99 ]; then
        return 0
      fi
      echo "pngquant failed ($rc): $source_path" >&2
      return "$rc"
      ;;
  esac
}

generate_webp() {
  source_path="$1"
  output_path="$2"

  if [ "${GENERATE_WEBP:-0}" != "1" ]; then
    return 0
  fi
  if [ -z "${CWEBP:-}" ]; then
    return 1
  fi

  "$CWEBP" -quiet -mt -q "${WEBP_QUALITY:-82}" "$source_path" -o "$output_path" >/dev/null 2>&1
  rc="$?"
  if [ "$rc" -ne 0 ]; then
    echo "cwebp failed ($rc): $source_path -> $output_path" >&2
  fi
  return "$rc"
}

generate_avif() {
  source_path="$1"
  output_path="$2"

  if [ "${GENERATE_AVIF:-0}" != "1" ]; then
    return 0
  fi
  if [ -z "${AVIFENC:-}" ]; then
    return 1
  fi

  "$AVIFENC" --min "${AVIF_QUALITY:-50}" --max "${AVIF_QUALITY:-50}" --speed "${AVIF_SPEED:-6}" "$source_path" "$output_path" >/dev/null 2>&1
  rc="$?"
  if [ "$rc" -ne 0 ]; then
    echo "avifenc failed ($rc): $source_path -> $output_path" >&2
  fi
  return "$rc"
}

while IFS="$TAB" read -r attachment_id relative_path source_mime absolute_path signature needs_original needs_webp webp_path needs_avif avif_path; do
  if [ -z "$relative_path" ]; then
    continue
  fi

  if already_processed "$relative_path" "$signature"; then
    continue
  fi

  if [ -f "$PAUSE_FLAG_FILE" ]; then
    write_status "paused" "$CURRENT_FILE"
    exit 0
  fi

  CURRENT_FILE="$relative_path"
  write_status "running" "$CURRENT_FILE"

  if [ ! -f "$absolute_path" ]; then
    FAILED=$((FAILED + 1))
    PROCESSED=$((PROCESSED + 1))
    append_failed "$relative_path" "$signature" "$attachment_id" "missing_source"
    write_status "running" "$CURRENT_FILE"
    continue
  fi

  original_done="0"
  webp_done="0"
  avif_done="0"
  failed_reason=""

  if [ "${needs_original:-0}" = "1" ]; then
    if optimize_original "$source_mime" "$absolute_path"; then
      if [ "${OPTIMIZE_ORIGINALS:-0}" = "1" ]; then
        case "$source_mime" in
          image/jpeg)
            if [ -n "${JPEGOPTIM:-}" ]; then
              original_done="1"
            fi
            ;;
          image/png)
            if [ -n "${PNGQUANT:-}" ]; then
              original_done="1"
            fi
            ;;
        esac
      fi
    else
      original_rc="$?"
      failed_reason="original_optimization_failed_${original_rc}"
    fi
  fi

  if [ -z "$failed_reason" ] && [ "${needs_webp:-0}" = "1" ]; then
    if generate_webp "$absolute_path" "$webp_path"; then
      webp_done="1"
    else
      webp_rc="$?"
      failed_reason="webp_generation_failed_${webp_rc}"
    fi
  fi

  if [ -z "$failed_reason" ] && [ "${needs_avif:-0}" = "1" ]; then
    if generate_avif "$absolute_path" "$avif_path"; then
      avif_done="1"
    else
      avif_rc="$?"
      failed_reason="avif_generation_failed_${avif_rc}"
    fi
  fi

  if [ -n "$failed_reason" ]; then
    FAILED=$((FAILED + 1))
    PROCESSED=$((PROCESSED + 1))
    append_failed "$relative_path" "$signature" "$attachment_id" "$failed_reason"
    write_status "running" "$CURRENT_FILE"
    continue
  fi

  SUCCESS=$((SUCCESS + 1))
  PROCESSED=$((PROCESSED + 1))
  append_done "$relative_path" "$signature" "$attachment_id" "$original_done" "$webp_done" "$avif_done" "$source_mime"
  write_status "running" "$CURRENT_FILE"
done < "$MANIFEST_FILE"

rm -f "$PAUSE_FLAG_FILE"
write_status "completed" ""
exit 0
