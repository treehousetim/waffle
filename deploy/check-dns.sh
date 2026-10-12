#!/usr/bin/env bash
#
# deploy/check-dns.sh <hostname> — does the hostname point at this server?
#
# Run by deploy.sh before the stack starts, because Caddy can only get a
# certificate for a name whose DNS leads here, and a launch that fails on DNS
# otherwise fails as "the site is down" with the reason buried in Caddy's log.
# docs/https.md has the failure list; the numbers below cite it.
#
# Exit 0 when every A and AAAA record is an address of this server, 1 with
# what to fix when not, 2 on a usage error.
#
set -euo pipefail

HOST="${1:-}"
if [ -z "$HOST" ]; then
    echo "usage: check-dns.sh <hostname>" >&2
    exit 2
fi

DIG="${DIG:-dig}"
# (5) A public resolver, not this machine's: /etc/hosts on a droplet often
# maps its own name to 127.0.1.1, and a local cache can hold an old answer.
# Let's Encrypt asks the public DNS, so this does too.
RESOLVER="${DNS_RESOLVER:-1.1.1.1}"

# (6) No dig, no check: finding nothing to compare is not a pass.
if ! command -v "$DIG" >/dev/null 2>&1; then
    echo "error: dig is not installed, so DNS cannot be checked." >&2
    echo "       install it: sudo apt install bind9-dnsutils" >&2
    exit 1
fi

lookup() {
    "$DIG" +short +time=3 +tries=2 "$1" "$HOST" "@$RESOLVER" 2>/dev/null || true
}

# dig +short prints a CNAME's target before its addresses; keep the addresses.
RECORDS_A="$(lookup A | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || true)"
RECORDS_AAAA="$(lookup AAAA | grep ':' | grep -v '\.$' || true)"

# Every address on this machine, plus (4) a DigitalOcean reserved IP, which is
# routed here without being on any interface.
LOCAL="$(hostname -I 2>/dev/null || true)"
RESERVED="$(curl -s -m 2 http://169.254.169.254/metadata/v1/reserved_ip/ipv4/ip_address 2>/dev/null \
            | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || true)"
LOCAL="$LOCAL $RESERVED"

is_local() {
    case " $(printf '%s' "$LOCAL" | tr '\n\t' '  ') " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

# This server's public IPv4 addresses, for the messages: what to point at.
PUBLIC="$(printf '%s\n' $LOCAL \
          | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' \
          | grep -v -E '^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' \
          | sort -u | tr '\n' ' ' || true)"
PUBLIC="${PUBLIC% }"
# Not ${PUBLIC:-...}: an apostrophe inside that default unbalances the quotes.
[ -n "$PUBLIC" ] || PUBLIC="this server's public address"

PROBLEMS=0

# (1) No A record at all.
if [ -z "$RECORDS_A" ]; then
    echo "error: ${HOST} has no A record (asked ${RESOLVER})." >&2
    echo "       add one pointing at ${PUBLIC}, at your DNS provider." >&2
    PROBLEMS=1
fi

# (2) An A record pointing somewhere else. Every one must be here: Let's
# Encrypt may validate against any of them.
for address in $RECORDS_A; do
    if ! is_local "$address"; then
        echo "error: ${HOST} points at ${address}, but this server is ${PUBLIC}." >&2
        echo "       change the A record to ${PUBLIC}." >&2
        PROBLEMS=1
    fi
done

# (3) An AAAA record for an address this server does not have. Let's Encrypt
# tries IPv6 first, so this fails the certificate even when the A record is right.
for address in $RECORDS_AAAA; do
    if ! is_local "$address"; then
        echo "error: ${HOST} has an AAAA (IPv6) record, ${address}, that is not this server." >&2
        echo "       Let's Encrypt tries IPv6 first, so the certificate would fail." >&2
        echo "       remove the AAAA record, or turn on IPv6 for the droplet and point it there." >&2
        PROBLEMS=1
    fi
done

if [ "$PROBLEMS" -ne 0 ]; then
    echo "       DNS changes can take a few minutes to spread; run the deploy again after fixing." >&2
    exit 1
fi

echo "ok: ${HOST} points at this server ($(printf '%s' "$RECORDS_A $RECORDS_AAAA" | tr '\n' ' ' | tr -s ' ' | sed 's/ $//'))"
