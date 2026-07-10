#!/usr/bin/env bash
#
# Run lint + static analysis + the unit suite against every PHP version in the
# CI matrix, using official php:<ver>-cli Docker images. Mirrors the CI "checks"
# job locally — no remote or pushed CI required. Reuses the installed vendor/
# (the composer platform pin means CI resolves the same set).
#
# Usage:
#   bin/test-matrix.sh           # every version in .github/workflows/ci.yml
#   bin/test-matrix.sh 8.3 8.5   # only the given versions
#
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

if ! docker info >/dev/null 2>&1; then
    echo "error: Docker is not available or not running." >&2
    exit 1
fi
if [ ! -x vendor/bin/phpunit ]; then
    echo "error: vendor/ missing — run 'composer install' first." >&2
    exit 1
fi

if [ "$#" -gt 0 ]; then
    versions=("$@")
else
    # Single source of truth: the CI matrix line. Fall back if it ever moves.
    mapfile -t versions < <(grep -E '^[[:space:]]*php:[[:space:]]*\[' .github/workflows/ci.yml | grep -oE '[0-9]+\.[0-9]+')
    [ "${#versions[@]}" -gt 0 ] || versions=(8.1 8.2 8.3 8.4 8.5)
fi

echo "PHP matrix: ${versions[*]}"
failed=()

for v in "${versions[@]}"; do
    echo "================= PHP $v ================="
    if docker run --rm \
        -v "$ROOT":/app -w /app \
        --user "$(id -u):$(id -g)" -e HOME=/tmp \
        "php:${v}-cli" \
        sh -c '
            php -v | head -1
            rc=0
            if php vendor/bin/phpcs --report=summary >/tmp/lint.log 2>&1; then echo "  lint: PASS"; else echo "  lint: FAIL"; tail -4 /tmp/lint.log | sed "s/^/      /"; rc=1; fi
            if php vendor/bin/phpstan analyse --no-progress --memory-limit=1G >/tmp/stan.log 2>&1; then echo "  stan: PASS"; else echo "  stan: FAIL"; tail -8 /tmp/stan.log | sed "s/^/      /"; rc=1; fi
            out=$(php vendor/bin/phpunit --testsuite unit 2>&1)
            if printf "%s" "$out" | grep -q "^OK"; then
                echo "  unit: PASS ($(printf "%s" "$out" | grep -oE "[0-9]+ tests, [0-9]+ assertions"))"
            else
                echo "  unit: FAIL"; printf "%s" "$out" | tail -6 | sed "s/^/      /"; rc=1
            fi
            exit $rc
        '; then
        :
    else
        failed+=("$v")
    fi
done

echo "========================================="
if [ "${#failed[@]}" -eq 0 ]; then
    echo "PASS — all ${#versions[@]} version(s): ${versions[*]}"
else
    echo "FAIL — ${failed[*]}"
    exit 1
fi
