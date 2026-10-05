#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_ID="$(openssl rand -hex 6)"
CONTAINER="kampy-respondent-${RUN_ID}"
TASK_LABEL="kampy.respondent.integration.owner=${RUN_ID}"
CONTAINER_CREATED=0
DATA_DIR="$(mktemp -d "${TMPDIR:-/tmp}/kampy-respondent-pg.XXXXXX")"
DB_USER="kampy_owner"
DB_PASSWORD="invented-registration-integration-only"
DB_NAME="respondent_test_${RUN_ID}"

cleanup() {
  local exit_code=$?
  local actual_label=""
  trap - EXIT INT TERM
  if [[ "${CONTAINER_CREATED}" -eq 1 ]]; then
    actual_label="$(docker inspect --format '{{ index .Config.Labels "kampy.respondent.integration.owner" }}' "${CONTAINER}" 2>/dev/null || true)"
    if [[ "${actual_label}" == "${RUN_ID}" ]]; then
      docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
    else
      echo "Refusing to remove container without the exact task ownership label" >&2
      if [[ "${exit_code}" -eq 0 ]]; then exit_code=1; fi
    fi
  fi
  rm -rf "${DATA_DIR}"
  exit "${exit_code}"
}
trap cleanup EXIT INT TERM

command -v docker >/dev/null
command -v openssl >/dev/null
DOCKER_ENDPOINT="${DOCKER_HOST:-$(docker context inspect "$(docker context show)" --format '{{ .Endpoints.docker.Host }}')}"
if [[ "${DOCKER_ENDPOINT}" != unix://* ]]; then
  echo "Registration integration requires a local Unix-socket Docker endpoint" >&2
  exit 1
fi
if docker container inspect "${CONTAINER}" >/dev/null 2>&1; then
  echo "Refusing to reuse an existing container name" >&2
  exit 1
fi
docker image inspect postgres:16-alpine >/dev/null
chmod 700 "${DATA_DIR}"

docker run --pull=never -d \
  --name "${CONTAINER}" \
  --label "${TASK_LABEL}" \
  -e "POSTGRES_USER=${DB_USER}" \
  -e "POSTGRES_PASSWORD=${DB_PASSWORD}" \
  -e "POSTGRES_DB=${DB_NAME}" \
  -v "${DATA_DIR}:/var/lib/postgresql/data" \
  -p 127.0.0.1::5432 \
  postgres:16-alpine >/dev/null
CONTAINER_CREATED=1

ready=0
for _ in $(seq 1 100); do
  if docker exec "${CONTAINER}" \
      psql -U "${DB_USER}" -d "${DB_NAME}" -Atqc 'select 1' 2>/dev/null \
      | grep -qx 1; then
    sleep 0.4
    if docker exec "${CONTAINER}" \
        psql -U "${DB_USER}" -d "${DB_NAME}" -Atqc 'select 1' 2>/dev/null \
        | grep -qx 1; then
      ready=1
      break
    fi
  fi
  sleep 0.2
done
if [[ "${ready}" -ne 1 ]]; then
  docker logs "${CONTAINER}" >&2 || true
  echo "Disposable PostgreSQL did not become stable" >&2
  exit 1
fi

PORT="$(docker port "${CONTAINER}" 5432/tcp | awk -F: 'NR == 1 { print $NF }')"
if [[ ! "${PORT}" =~ ^[0-9]+$ ]]; then
  echo "Could not resolve disposable PostgreSQL port" >&2
  exit 1
fi
export KAMP_RESPONDENT_DISPOSABLE=1 DB_HOST=127.0.0.1 DB_PORT="${PORT}" DB_DATABASE="${DB_NAME}" DB_USERNAME="${DB_USER}" DB_PASSWORD="${DB_PASSWORD}"
export APP_ENV=testing MAIL_MAILER=array CACHE_STORE=array SESSION_DRIVER=array
cd "${ROOT}"
if [[ "${1:-}" == browser ]]; then
  node tests/browser/respondent-confirmation-journey.mjs
elif [[ "${1:-}" == checkout-browser ]]; then
  RESPONDENT_FULL_CHECKOUT=1 node tests/browser/respondent-checkout-journey.mjs
elif [[ "${1:-}" == checkout ]]; then
  vendor/bin/phpunit tests/Integration/RespondentPurchaseContactTest.php
else
  vendor/bin/phpunit tests/Integration/RespondentConfirmationPostgresTest.php
fi
