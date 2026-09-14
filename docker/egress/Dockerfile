# syntax=docker/dockerfile:1
# Short-lived helper that writes one environment's egress rules into the host
# netfilter tables. It runs in the host network namespace with NET_ADMIN and is
# never reachable by an environment: see docs/deployment.md.
ARG EGRESS_BASE_IMAGE=alpine:3.21
FROM ${EGRESS_BASE_IMAGE}
RUN apk add --no-cache iptables
COPY secops-egress /usr/local/bin/secops-egress
RUN chmod 0755 /usr/local/bin/secops-egress
ENTRYPOINT ["/usr/local/bin/secops-egress"]
