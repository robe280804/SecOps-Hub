# syntax=docker/dockerfile:1
ARG KALI_IMAGE=kalilinux/kali-rolling@sha256:c6d78f57ebfdd9fec428a8b04f74876e66fddd08dfd328c076224a552e7332a3
FROM ${KALI_IMAGE} AS base
COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /usr/local/bin/
ENV UV_TOOL_DIR=/opt/uv/tools \
    UV_TOOL_BIN_DIR=/usr/local/bin \
    UV_PYTHON_INSTALL_DIR=/opt/uv/python \
    UV_LINK_MODE=copy \
    GOBIN=/usr/local/bin \
    PYTHONUNBUFFERED=1
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    ca-certificates python3 python3-pip python3-venv pipx golang-go ruby ruby-dev \
    build-essential libssl-dev libffi-dev pkg-config git curl wget jq procps \
    iproute2 tini tmux bash && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/secops-image
COPY install.py test_install.py ./
RUN python3 -m unittest -v test_install

FROM base AS runtime
COPY tools.json ./tools.json
RUN python3 install.py tools.json && \
    rm -rf /var/lib/apt/lists/* /root/.cache /root/go/pkg/mod && \
    mkdir -p /workspace
WORKDIR /workspace
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sleep", "infinity"]
