#!/usr/bin/env bash

set -euo pipefail

mode="${1:-build}"

if [ ! -f ".env.docker" ]; then
  echo "ERROR: .env.docker not found in $(pwd)."
  echo "Create it from .env.docker.example before deploying."
  exit 1
fi

if [ "$mode" = "pull" ]; then
  docker compose pull app worker
else
  docker compose build --pull app worker
fi

docker compose up -d app worker
docker compose ps
