#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAME="restic"
VERSION="$(tr -d '\n' < "${ROOT_DIR}/VERSION")"

ARTIFACT_BASE_URL="${ARTIFACT_BASE_URL:-${RAW_BASE_URL:-https://raw.githubusercontent.com/your-user/unraid-restic-plugin/main/dist}}"
PACKAGE_SUBDIR="${PACKAGE_SUBDIR-packages}"
SUPPORT_URL="${SUPPORT_URL:-https://github.com/your-user/unraid-restic-plugin}"

DIST_DIR="${ROOT_DIR}/dist"
PACKAGE_DIR="${DIST_DIR}/packages"
PACKAGE_NAME="${NAME}-${VERSION}-noarch-1.txz"
PACKAGE_PATH="${PACKAGE_DIR}/${PACKAGE_NAME}"
PLG_TEMPLATE="${ROOT_DIR}/templates/${NAME}.plg.in"
PLG_PATH="${DIST_DIR}/${NAME}.plg"

mkdir -p "${PACKAGE_DIR}"
rm -f "${PACKAGE_DIR}"/${NAME}-*-noarch-1.txz "${PLG_PATH}"

export COPYFILE_DISABLE=1
tar -C "${ROOT_DIR}/source" -cJf "${PACKAGE_PATH}" .

if command -v md5sum >/dev/null 2>&1; then
  PACKAGE_MD5="$(md5sum "${PACKAGE_PATH}" | awk '{print $1}')"
elif command -v md5 >/dev/null 2>&1; then
  PACKAGE_MD5="$(md5 -q "${PACKAGE_PATH}")"
else
  echo "Unable to find md5sum or md5" >&2
  exit 1
fi

PLUGIN_URL="${ARTIFACT_BASE_URL%/}/${NAME}.plg"
if [[ -n "${PACKAGE_SUBDIR}" ]]; then
  PACKAGE_URL="${ARTIFACT_BASE_URL%/}/${PACKAGE_SUBDIR}/${PACKAGE_NAME}"
else
  PACKAGE_URL="${ARTIFACT_BASE_URL%/}/${PACKAGE_NAME}"
fi

sed \
  -e "s|@VERSION@|${VERSION}|g" \
  -e "s|@PLUGIN_URL@|${PLUGIN_URL}|g" \
  -e "s|@PACKAGE_URL@|${PACKAGE_URL}|g" \
  -e "s|@PACKAGE_MD5@|${PACKAGE_MD5}|g" \
  -e "s|@SUPPORT_URL@|${SUPPORT_URL}|g" \
  "${PLG_TEMPLATE}" > "${PLG_PATH}"

echo "Built package: ${PACKAGE_PATH}"
echo "Built plugin:  ${PLG_PATH}"
echo "Artifact URL:  ${ARTIFACT_BASE_URL}"
