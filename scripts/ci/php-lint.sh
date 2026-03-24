#!/usr/bin/env bash

set -euo pipefail

mapfile -t php_files < <(git ls-files "*.php")

if [ "${#php_files[@]}" -eq 0 ]; then
  echo "No PHP files found to lint."
  exit 0
fi

for file in "${php_files[@]}"; do
  php -l "$file" > /dev/null
done

echo "PHP lint passed for ${#php_files[@]} file(s)."
