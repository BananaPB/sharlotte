# One Linux toolchain for every place an agent or a human runs this project's commands:
# VS Code (Dev Containers) and the unattended nightshift runner. It exists mainly because
# `npm run check:fix` cannot run from Git Bash on Windows (docs/git-github-workflow.md §9.4);
# here it just works, so agents format before pushing instead of waiting for CI to complain.
# Versions mirror .github/workflows/tests.yml (PHP 8.5, Node 22) so "green here" means "green in CI".

FROM php:8.5-cli

ARG USERNAME=dev
ARG USER_UID=1000
ARG USER_GID=1000

# System packages: PHP extension build deps, Postgres client (pg_isready), git, jq for the runner.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl ca-certificates gnupg unzip jq procps \
        libpq-dev libicu-dev libzip-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql pgsql intl zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

# Composer, from the official image (same major as CI's `composer:v2`).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 + npm, copied from the official image rather than a third-party apt repo.
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

# GitHub CLI, from GitHub's own apt repository.
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        -o /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

# Non-root user: agents never need root, and files they create stay owned by uid 1000 on the host.
RUN groupadd --gid ${USER_GID} ${USERNAME} \
    && useradd --uid ${USER_UID} --gid ${USER_GID} --create-home --shell /bin/bash ${USERNAME}

USER ${USERNAME}
ENV PATH="/home/${USERNAME}/.local/bin:${PATH}"
# Pre-create the directories compose mounts named volumes on, so the volumes start owned by `dev`.
RUN mkdir -p /home/${USERNAME}/.claude /home/${USERNAME}/.cache/composer /home/${USERNAME}/.npm

# Claude Code, native installer (user-level, auto-updates are irrelevant in a rebuilt image).
RUN curl -fsSL https://claude.ai/install.sh | bash && claude --version

WORKDIR /workspace
