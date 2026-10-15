# gh and pre-commit for `make release` on a host that lacks either. The Makefile runs them from this
# image only then (see RELEASE_TOOLS_IN_DOCKER), as the invoking user, with the GitHub token passed
# by NAME (`-e GH_TOKEN`) so its value never reaches an argv. Every version is pinned: the base image
# by tag AND digest, gh by version AND the sha256 of its release tarball, pre-commit by version.
FROM python:3.12.12-slim-bookworm@sha256:593bd06efe90efa80dc4eee3948be7c0fde4134606dd40d8dd8dbcade98e669c

ARG GH_VERSION=2.100.0
ARG GH_SHA256=e4d4bb4498e8d007abe545b6568926793ace1b6447da598294a610018cb164be
ARG PRE_COMMIT_VERSION=4.6.2

# git: pre-commit lists and stashes files through it, and fetches the hook repositories with it.
# curl + ca-certificates: the gh download below. libatomic1: the node binary pre-commit downloads
# for the markdownlint-cli2 hook links against it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl ca-certificates libatomic1 \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/gh.tar.gz \
        "https://github.com/cli/cli/releases/download/v${GH_VERSION}/gh_${GH_VERSION}_linux_amd64.tar.gz" \
    && echo "${GH_SHA256}  /tmp/gh.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/gh.tar.gz -C /tmp \
    && install -m 0755 "/tmp/gh_${GH_VERSION}_linux_amd64/bin/gh" /usr/local/bin/gh \
    && rm -rf /tmp/gh.tar.gz "/tmp/gh_${GH_VERSION}_linux_amd64"

RUN python3 -m pip install --no-cache-dir "pre-commit==${PRE_COMMIT_VERSION}"
