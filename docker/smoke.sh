#!/usr/bin/env bash
###############################################################################
# King Builders Business OS — post-deploy smoke test (M12)
#
#   ./docker/smoke.sh https://erp.example.com
#
# Read-only. Checks the public surface responds correctly WITHOUT logging in:
#   - liveness + readiness
#   - HTTPS redirect
#   - security headers present
#   - login page renders
#   - every authenticated area + export is gated (302/403, never 200/500)
#   - a stored document cannot be reached anonymously
#
# Exit non-zero on the first failure.
###############################################################################
set -Eeuo pipefail
BASE="${1:?usage: smoke.sh <base-url>}"
BASE="${BASE%/}"
fails=0

check() { # name  expected-code  url  [curl-opts...]
  local name="$1" want="$2" url="$3"; shift 3
  local got
  got="$(curl -sk -o /dev/null -w '%{http_code}' "$@" "$url")"
  if [ "$got" = "$want" ]; then
    printf '  ok   %-42s %s\n' "$name" "$got"
  else
    printf '  FAIL %-42s got %s, want %s\n' "$name" "$got" "$want"; fails=$((fails+1))
  fi
}

echo "smoke: $BASE"

check "liveness  /up"        200 "$BASE/up"
check "readiness /healthz"   200 "$BASE/healthz"
check "login page"           200 "$BASE/login"
check "root → dashboard → login" 302 "$BASE/"

# authenticated areas must redirect a guest to /login (302), never 200 or 500
for path in /dashboard /projects /plots /leads /buyers /bookings /payments \
            /finance /collections /documents/dashboard /registry/dashboard \
            /possession/dashboard /transfers/dashboard /users /roles \
            /reports /reports/sales /reports/inventory /reports/collections /reports/mis; do
  check "guest-gated $path" 302 "$BASE$path"
done

# exports must be gated too
for fmt in csv xlsx pdf print; do
  check "guest-gated export/$fmt" 302 "$BASE/reports/mis/export/$fmt"
done

# a private document endpoint must never serve to an anonymous caller
check "doc download gated" 302 "$BASE/documents/1/versions/1/download"

# security headers on the login page
hdrs="$(curl -sk -D - -o /dev/null "$BASE/login")"
for h in "X-Content-Type-Options: nosniff" "X-Frame-Options: SAMEORIGIN" "Referrer-Policy:"; do
  if grep -qi "^${h}" <<<"$hdrs"; then printf '  ok   header %-36s\n' "$h"; else
    printf '  FAIL header %-36s missing\n' "$h"; fails=$((fails+1)); fi
done

# HTTP → HTTPS redirect (only meaningful when BASE is https)
case "$BASE" in
  https://*) check "http→https redirect" 301 "${BASE/https:/http:}/dashboard" ;;
esac

echo
if [ "$fails" -eq 0 ]; then echo "smoke: PASS"; else echo "smoke: $fails FAILURE(S)"; exit 1; fi
