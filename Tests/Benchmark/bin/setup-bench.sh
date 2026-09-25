#!/usr/bin/env bash
#
# Builds the benchmark target: a vanilla TYPO3 13 install in its own DDEV
# project, with the docroot on the HOST filesystem.
#
# The host mount is the whole point. The dev harness in .ddev/ keeps its v13/v14
# installs in named Docker volumes (docker-compose.web.yaml), which is fine for
# development but fatal for benchmarking: Claude Code runs on the host, so the
# no-MCP baseline arm would have no Read/Grep/Glob at all and could only reach
# files through `ddev exec cat`. That handicaps the control arm and inflates the
# MCP's measured value. Here the project directory is mounted normally, so both
# arms get the same file access a real developer has.
#
# The extension repo is bind-mounted at /var/www/dev_mcp and installed through a
# Composer path repository, mirroring .ddev/commands/web/install-v13. Set
# DEV_MCP_CONSTRAINT (e.g. 1.0.0) to install a released version from Packagist
# instead, which benchmarks exactly what shipped.
#
# BENCH_TYPO3=14 builds the TYPO3 v14 bench as a separate DDEV project.
#
# Usage: [BENCH_TYPO3=14] [DEV_MCP_CONSTRAINT=1.0.0] setup-bench.sh [--recreate]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$BENCH_DIR/../.." && pwd)"

# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"

PROJECT_NAME="typo3-dev-mcp-bench${BENCH_SUFFIX:+-$BENCH_SUFFIX}"
BENCH_ROOT="${BENCH_ROOT:-$(dirname "$REPO_ROOT")/$PROJECT_NAME}"
SITE_URL="https://${PROJECT_NAME}.ddev.site"
ADMIN_PASSWORD='Joh316!!'
RECREATE=0

# v14 has no t3/cms meta package. This list mirrors what t3/cms installs on v13;
# cms-base-distribution would add impexp, whose extra tt_content column breaks
# the "which columns are non-core" task.
case "$BENCH_TYPO3" in
    13)
        TYPO3_VERSION=13.4
        TYPO3_PACKAGES="t3/cms:'^13'"
        ;;
    14)
        TYPO3_VERSION=14.3
        TYPO3_PACKAGES="typo3/minimal:'^14.3' helhum/typo3-console:'^9'"
        for ext in belog beuser fluid-styled-content info install lowlevel rte-ckeditor setup tstemplate; do
            TYPO3_PACKAGES="$TYPO3_PACKAGES typo3/cms-$ext:'^14.3'"
        done
        ;;
esac

if [[ -n "${DEV_MCP_CONSTRAINT:-}" ]]; then
    DEV_MCP_REPO=":"
    DEV_MCP_PACKAGE="balatd/typo3-dev-mcp:'$DEV_MCP_CONSTRAINT'"
else
    DEV_MCP_REPO="composer config repositories.dev_mcp path /var/www/dev_mcp"
    DEV_MCP_PACKAGE="balatd/typo3-dev-mcp:'*@dev'"
fi

[[ "${1:-}" == "--recreate" ]] && RECREATE=1

# The bench project holds a copy of the fixture, not a symlink: a symlink would
# have to resolve to different paths on the host and inside the container. Copies
# mean fixture edits need an explicit sync.
if [[ "${1:-}" == "--sync-fixture" ]]; then
    load_bench_env
    rsync -a --delete "$BENCH_DIR/fixture/bench_fixture/" "$BENCH_ROOT/packages/bench_fixture/"
    rm -rf "$BENCH_ROOT/var/cache"
    ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 extension:setup
    ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 cache:flush
    # The fixture is tracked in the bench's git, and every run resets with
    # `git checkout`, so an uncommitted sync would be reverted by the first run.
    (
        cd "$BENCH_ROOT"
        git add -A packages/bench_fixture
        git -c user.email=bench@local -c user.name=bench commit -qm "bench: sync fixture" || true
    )
    echo "fixture synced"
    exit 0
fi

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [[ -d "$BENCH_ROOT" && $RECREATE -eq 1 ]]; then
    say "Removing existing bench project at $BENCH_ROOT"
    (cd "$BENCH_ROOT" && ddev delete -Oy >/dev/null 2>&1) || true
    rm -rf "$BENCH_ROOT"
fi

if [[ -d "$BENCH_ROOT" ]]; then
    echo "Bench project already exists at $BENCH_ROOT"
    echo "Use --recreate to rebuild it from scratch."
    exit 0
fi

say "Creating $BENCH_ROOT"
mkdir -p "$BENCH_ROOT"
cd "$BENCH_ROOT"

# Stage the fixture before `ddev start`. Copying it in after the container is up
# races the bind mount: Composer resolves the path repository against a
# directory listing the container has not picked up yet and fails with
# "The `url` supplied for the path repository does not exist".
if [[ -d "$BENCH_DIR/fixture/bench_fixture" ]]; then
    mkdir -p "$BENCH_ROOT/packages"
    cp -R "$BENCH_DIR/fixture/bench_fixture" "$BENCH_ROOT/packages/bench_fixture"
fi

ddev config \
    --project-name="$PROJECT_NAME" \
    --project-type=typo3 \
    --docroot=public \
    --php-version=8.3 \
    --webserver-type=apache-fpm \
    --disable-upload-dirs-warning \
    >/dev/null

# Written before seeding: seed-content.sh and every later script resolve the
# project through this file, so writing it last would seed the wrong bench.
cat > "$BENCH_ENV_FILE" <<EOF
BENCH_ROOT=$BENCH_ROOT
PROJECT_NAME=$PROJECT_NAME
SITE_URL=$SITE_URL
TYPO3_VERSION=$TYPO3_VERSION
EOF

# Bind-mount the extension repo so Composer's path repository can reach it.
cat > .ddev/docker-compose.ext.yaml <<EOF
services:
    web:
        environment:
            - TYPO3_CONTEXT=Development/Ddev
        volumes:
            - type: bind
              source: $REPO_ROOT
              target: /var/www/dev_mcp
              consistency: cached
EOF

say "Starting DDEV"
ddev start >/dev/null

say "Installing TYPO3 $TYPO3_VERSION + EXT:dev_mcp"
ddev exec bash -s <<INNER
set -e
cd /var/www/html

echo "{}" > composer.json
composer config extra.typo3/cms.web-dir public
$DEV_MCP_REPO
composer config --no-plugins allow-plugins.typo3/cms-composer-installers true
composer config --no-plugins allow-plugins.typo3/class-alias-loader true
composer req $TYPO3_PACKAGES $DEV_MCP_PACKAGE --no-progress -n
INNER

say "Running TYPO3 setup"
ddev exec bash -s <<INNER
set -e
cd /var/www/html

vendor/bin/typo3 setup -n \\
    --driver=mysqli \\
    --host=db --port=3306 \\
    --dbname=db --username=db --password=db \\
    --admin-username=admin \\
    --admin-user-password='$ADMIN_PASSWORD' \\
    --admin-email=admin@example.com \\
    --project-name='typo3-dev-mcp benchmark' \\
    --server-type=apache \\
    --create-site='$SITE_URL'

vendor/bin/typo3 configuration:set 'BE/debug' 1
vendor/bin/typo3 configuration:set 'FE/debug' 1
vendor/bin/typo3 configuration:set 'SYS/devIPmask' '*'
vendor/bin/typo3 configuration:set 'SYS/displayErrors' 1

mkdir -p config/system
{
    echo '<?php'
    echo '\$GLOBALS["TYPO3_CONF_VARS"]["SYS"]["trustedHostsPattern"] = ".*";'
    echo '\$GLOBALS["TYPO3_CONF_VARS"]["SYS"]["features"]["security.backend.enforceReferrer"] = false;'
} > config/system/additional.php

# Enable the deprecation log writer so the log tools have data to read.
sed -i "/'deprecations'/,/^[[:space:]]*'disabled' => true,/s/'disabled' => true,/'disabled' => false,/" config/system/settings.php

vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
INNER

say "Seeding bench_fixture"
if [[ -d "$BENCH_ROOT/packages/bench_fixture" ]]; then
    ddev exec bash -s <<'INNER'
set -e
cd /var/www/html
test -d packages/bench_fixture || { echo "fixture not visible in container" >&2; exit 1; }
composer config repositories.bench_fixture path packages/bench_fixture
composer req balatd/bench-fixture:'*@dev' --no-progress -n
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
INNER
    say "Attaching the fixture site set"
    "$BENCH_DIR/bin/seed-content.sh"
else
    echo "  (no fixture — skipping)"
fi

say "Initialising git for per-run reset"
cat > .gitignore <<'EOF'
/vendor/
/public/_assets/
/public/fileadmin/_processed_/
/public/index.php
/public/typo3
/var/
EOF
git init -q
git add -A
git -c user.email=bench@local -c user.name=bench commit -qm "bench: clean baseline"

say "Taking clean DB snapshot"
ddev snapshot --name bench-clean >/dev/null

# devmcp:install artefacts are arm state, never part of the clean baseline.
rm -f .mcp.json CLAUDE.md AGENTS.md
rm -rf .ai

say "Done"
echo
echo "  project : $BENCH_ROOT"
echo "  backend : $SITE_URL/typo3/  (admin / $ADMIN_PASSWORD)"
echo "  snapshot: bench-clean"
echo
echo "Next: BENCH_TYPO3=$BENCH_TYPO3 Tests/Benchmark/bin/verify-arms.sh"
