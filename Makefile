# =====================================================================================
# ondewo-sip-client-php - Makefile
#
# The ONDEWO SIP (SIP Telephony) gRPC client for PHP. The transport surface is
# generated from the .proto definitions of ondewo-sip-api by the ondewo-proto-compiler's
# `ondewo-php-proto-compiler` image into src/ (committed - see .gitignore), the only hand-written
# code is the bearer-token auth surface in auth/, and the repository root IS the composer package
# that gets published.
#
# Quick start:
#   make help                    # list every documented target
#   make makefile_chapters       # list the section headers below
#   make update_submodules       # fetch ondewo-sip-api + ondewo-proto-compiler
#   make build                   # submodules -> compiler image -> stubs -> version bump
#   make test                    # manifest validation + php -l + stub inventory + PHPUnit
#   make ci                      # exactly what .github/workflows/ci.yml runs (no submodules)
#
# Versioning: ONDEWO_SIP_VERSION (below) is the single source of truth and MUST
# match the ONDEWO SIP API in major and minor version. `make update_composer_version`
# propagates it into composer.json - never edit that field by hand.
#
# Overriding variables: pass on the command line, e.g. `make build VERIFY_ATTEMPTS=40`, or set
# them in the environment. Credentials (GITHUB_GH_TOKEN, PACKAGIST_USERNAME, PACKAGIST_API_TOKEN)
# are only ever read at runtime from the ENVIRONMENT and must never be committed.
#
# There is deliberately NO bare `export` at the top of this file. It used to push every variable,
# credentials included, into every process a recipe starts - composer, phpunit, the pre-commit
# hooks, the TLS test server. A recipe that needs a credential reads it as $${NAME} from the
# environment it was handed, and `release` runs every step that needs none of them under
# $(WITHOUT_CREDENTIALS).
# =====================================================================================

# ---------------- BEFORE RELEASE ----------------
# 1 - Update Version Number (ONDEWO_SIP_VERSION below)
# 2 - Update RELEASE.md
# 3 - make build
# -------------- Release Process Steps (make ondewo_release) --------------
# 1 - spc: release branch, tag and Packagist version are all still free
# 2 - Load the credentials from devops-accounts and DELETE the clone again, before anything runs
# 3 - make release:
#     release_preflight (read-only: credentials valid, version free, notes present, host tools)
#     -> build -> pre-commit -> the full test suite -> local commit
#     -> push, release branch, release tag (Packagist serves the TAG, so it has to exist first)
#     -> publish (the Packagist update ping) -> GitHub release -> release_verify (read-only:
#        tag on origin, non-empty GitHub release, the tag's release.yml run green, Packagist
#        serves the version)

########################################################
# 		Variables
########################################################

# MUST BE THE SAME AS THE API in Major and Minor Version Number
# example: API 2.9.0 --> Client 2.9.X
ONDEWO_SIP_VERSION=5.5.2

# Submodule pins. Both are checked out by `make checkout_defined_submodule_versions`, so the
# stubs of a release are always reproducible from the two commits recorded here.
ONDEWO_SIP_API_GIT_BRANCH=tags/5.5.2
ONDEWO_PROTO_COMPILER_GIT_BRANCH=tags/5.15.7

# Submodule directories - both sit at the repository root, see .gitmodules
ONDEWO_SIP_API_DIR=ondewo-sip-api
ONDEWO_PROTO_COMPILER_DIR=ondewo-proto-compiler

# The image is tagged with the compiler VERSION it was built from, never `latest`: `latest` is
# shared with every other ondewo client built on the same docker host (the vtsi and csi clients pin
# other compiler releases), so an interleaved release of another product could regenerate this
# client with a different compiler. `make build_compiler` builds this very tag from the submodule
# (not through the submodule's build.sh, which hard-codes `:latest`), and `make
# generate_ondewo_protos` runs it.
PROTO_COMPILER_VERSION=$(subst /,-,$(patsubst tags/%,%,${ONDEWO_PROTO_COMPILER_GIT_BRANCH}))
PROTO_COMPILER_IMAGE=ondewo-php-proto-compiler:${PROTO_COMPILER_VERSION}
# The compiler's scratch directory. It receives copies of exactly what the image needs - never the
# repository root, which is where a devops-accounts clone used to live during a release.
PROTO_COMPILER_WORK_DIR=build/proto-compiler

# Positional arguments of the compiler image's entrypoint:
#   <relative_protos_dir>  protoc's -I root INSIDE the input volume -> the api submodule
#   <target_subdir>        sub-directory of that root to scope generation to. `ondewo` keeps
#                          the vendored google/ tree out of the ENTRY set, while the image's
#                          dependency resolver still pulls in the google protos that are
#                          actually imported (google/api/annotations.proto, ...).
PROTOS_TARGET_SUBDIR=ondewo

# The dev tool chain (PHPUnit + the coverage gate) lives in its OWN composer project under tools/,
# never in the root manifest's require-dev - see tools/README.md: `composer update --no-dev` still
# RESOLVES require-dev, and the compiler image resolves the merged manifest with the network off,
# so one require-dev entry in composer.json takes `make generate_ondewo_protos` down with it.
TOOLS_DIR=tools
PHPUNIT=${TOOLS_DIR}/vendor/bin/phpunit
COVERAGE_CHECK=${TOOLS_DIR}/vendor/bin/coverage-check
CLOVER_REPORT=build/coverage/clover.xml
# The hand-written sources. MUST stay in sync with phpunit.xml.dist's <source><include>.
COVERAGE_SOURCE_DIR=auth
# Minimum coverage of the HAND-WRITTEN sources. The generated stubs are machine output and are
# excluded from the metric - they are exercised instead by tests/Generated/*, which loads every
# generated class and initialises every proto descriptor.
COVERAGE_MIN=100
# pcov AUTO-DETECTS pcov.directory and picks this repository's src/ - the generated tree - which
# makes it instrument nothing that phpunit.xml.dist's <source> covers and report a flat 0%. The
# directive is PHP_INI_SYSTEM, so phpunit.xml.dist's <ini> cannot set it; it has to be passed on
# the interpreter's command line. Harmless when the driver is xdebug (unknown directive, ignored).
PHP_COVERAGE_FLAGS=-d pcov.enabled=1 -d pcov.directory=${COVERAGE_SOURCE_DIR}
# The negative TLS tests fail handshakes ON PURPOSE, and ext-grpc logs every failed handshake at
# INFO - preceded by absl's "WARNING: All log messages before absl::InitializeLog() ..." - into
# the build log. ERROR keeps real gRPC errors visible and the expected noise out.
GRPC_VERBOSITY=ERROR
# The only variable the recipes' child processes need from this file (phpunit and ext-grpc read it).
export GRPC_VERBOSITY

# php and composer come from the host when it has them (CI: setup-php), and otherwise from
# PHP_TOOLCHAIN_IMAGE, built from tools/php.Dockerfile with every version pinned. The release
# validates, tests and publishes from a developer machine, where php is not otherwise required.
# The container runs as the invoking user on the same path, so everything it writes is theirs.
PHP_TOOLCHAIN_IMAGE=ondewo-sip-client-php-tools:local
PHP_IN_DOCKER=docker run --rm --user $$(id -u):$$(id -g) -e HOME=/tmp -e GRPC_VERBOSITY \
	-v "$(CURDIR)":"$(CURDIR)" -w "$(CURDIR)" ${PHP_TOOLCHAIN_IMAGE}
HOST_HAS_PHP:=$(shell command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1 && echo yes)
# NOT named COMPOSER: composer reads $COMPOSER as the name of the manifest it should load, and a
# variable of that name set in the environment would reach it.
PHP_CMD=$(if ${HOST_HAS_PHP},php,${PHP_IN_DOCKER} php)
COMPOSER_CMD=$(if ${HOST_HAS_PHP},composer,${PHP_IN_DOCKER} composer)

# You need to setup an access token at https://github.com/settings/tokens - permissions are important
GITHUB_GH_TOKEN?=ENTER_YOUR_TOKEN_HERE

# Terminate on the ***** separator that delimits release entries, NOT on /\*\*/ - that matched the
# first markdown **bold** span inside the entry and silently truncated the notes there, with no
# error from `gh release create`. Same fix as ondewo-nlu-api's and ondewo-nlu-client-python's Makefile.
# The heading is ANCHORED (^ ... $) and the version is quoted (\Q...\E): unanchored, with its dots
# meaning "any character", 9.1.1 also matched the heading of 9.1.10 and sliced that entry instead.
CURRENT_RELEASE_NOTES=`cat RELEASE.md \
	| perl -ne 'print if /^\#+ Release ONDEWO SIP PHP Client \Q${ONDEWO_SIP_VERSION}\E$$/../^\*{5}/'`

GH_REPO="https://github.com/ondewo/ondewo-sip-client-php"
GH_REPO_SLUG=ondewo/ondewo-sip-client-php
DEVOPS_ACCOUNT_GIT=ondewo-devops-accounts
DEVOPS_ACCOUNT_REPO=git@bitbucket.org:ondewo/${DEVOPS_ACCOUNT_GIT}.git
# EMPTY by default: `make ondewo_release` then clones devops-accounts into a private `mktemp -d`
# directory OUTSIDE this repository and deletes it again right after reading the credentials. Set it
# (ondewo-sip-api's release driver does, to its single clone) and that directory is used as is:
# never cloned, never deleted - its owner cleans it up.
DEVOPS_ACCOUNT_DIR?=
# ondewo-sip-api's release driver also runs release_preflight and release_verify STANDALONE, with
# nothing but DEVOPS_ACCOUNT_DIR set. Those two therefore load what they need from that directory -
# each credential only when the environment does not already carry it (inside `make release` it
# does) - into their own recipe shell: anchored grep, `set -a` + eval, so no value reaches an argv or
# the log. A no-op when DEVOPS_ACCOUNT_DIR is unset or not a directory.
LOAD_DEVOPS_CREDENTIALS=if [ -n "${DEVOPS_ACCOUNT_DIR}" ] && [ -d "${DEVOPS_ACCOUNT_DIR}" ]; then \
		set -a; \
		[ -n "$${GITHUB_GH_TOKEN:-}" ] || eval "$$(grep -h -E '^GITHUB_GH_TOKEN=' "${DEVOPS_ACCOUNT_DIR}/account_github.env" 2> /dev/null)"; \
		[ -n "$${PACKAGIST_USERNAME:-}" ] && [ -n "$${PACKAGIST_API_TOKEN:-}" ] \
			|| eval "$$(grep -h -E '^(PACKAGIST_USERNAME|PACKAGIST_API_TOKEN)=' "${DEVOPS_ACCOUNT_DIR}/account_packagist.env" 2> /dev/null)"; \
		set +a; \
	fi

# Every step of `release` that needs no credential runs under this prefix, so composer, phpunit, the
# pre-commit hooks, the compiler image and git never see one. Only the gh calls and the Packagist
# requests get them, and only as $${NAME} read from the environment.
WITHOUT_CREDENTIALS=env -u GITHUB_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN -u PACKAGIST_USERNAME -u PACKAGIST_API_TOKEN

# gh and pre-commit come from the host when it has them, and otherwise from RELEASE_TOOLS_IMAGE,
# built from tools/release-tools.Dockerfile with the gh and pre-commit versions pinned. So a release
# needs only docker, make, git, perl and curl on the host. The token reaches the container through
# `-e GH_TOKEN` (the NAME only - docker copies the value from its own environment), never on argv.
RELEASE_TOOLS_IMAGE=ondewo-sip-client-php-release-tools:local
RELEASE_TOOLS_IN_DOCKER=docker run --rm --user $$(id -u):$$(id -g) -e HOME=/tmp -e GH_TOKEN \
	-e PRE_COMMIT_HOME="$(CURDIR)/build/pre-commit-cache" \
	-v "$(CURDIR)":"$(CURDIR)" -w "$(CURDIR)" ${RELEASE_TOOLS_IMAGE}
HOST_HAS_GH:=$(shell command -v gh >/dev/null 2>&1 && echo yes)
HOST_HAS_PRECOMMIT:=$(shell command -v pre-commit >/dev/null 2>&1 && echo yes)
GH_CMD=$(if ${HOST_HAS_GH},gh,${RELEASE_TOOLS_IN_DOCKER} gh)
PRECOMMIT_CMD=$(if ${HOST_HAS_PRECOMMIT},pre-commit,${RELEASE_TOOLS_IN_DOCKER} pre-commit)

# `make release` is started by `ondewo_release` through this alias rather than through a literal
# $$(MAKE): GNU make EXECUTES a recipe line that references $$(MAKE) even under `make -n`, so
# `make -n ondewo_release` would have cloned devops-accounts and loaded the credentials just to show
# the plan. Referenced through another variable, the line is only printed.
SUBMAKE=$(MAKE)

# release_verify polls: Packagist crawls asynchronously and its p2 metadata is CDN-cached, and the
# tag's release.yml run takes minutes. 20 x 30 s = 10 minutes per check.
VERIFY_ATTEMPTS?=20
VERIFY_INTERVAL_S?=30

# ---------------- PACKAGIST ----------------
# Packagist has NO upload endpoint. It serves the tree of a git TAG of this very repository, so
# "publishing" is the tag that `make create_release_tag` already pushes plus a ping that tells
# Packagist to crawl it - `make publish`. The package itself has to be submitted ONCE by hand
# before any of this works; see README "Publishing to Packagist".
# Credentials: the Packagist login name and the token from https://packagist.org/profile/
# ("Show API token"). Both are read at runtime from the ondewo-devops-accounts repo
# (account_packagist.env) or from GitHub secrets - never committed.
PACKAGIST_USERNAME?=ENTER_HERE_YOUR_PACKAGIST_USERNAME
PACKAGIST_API_TOKEN?=ENTER_HERE_YOUR_PACKAGIST_API_TOKEN
PACKAGIST_PACKAGE=ondewo/sip-client-php
PACKAGIST_UPDATE_API=https://packagist.org/api/update-package
# The composer v2 metadata Packagist SERVES to `composer require` - the authoritative "is version X
# installable" source - and the public package page that lists its maintainers.
PACKAGIST_P2_URL=https://repo.packagist.org/p2/${PACKAGIST_PACKAGE}.json
PACKAGIST_PACKAGE_JSON_URL=https://packagist.org/packages/${PACKAGIST_PACKAGE}.json
# Exit 0 when the p2 JSON on stdin lists version $$V of package $$P, 1 otherwise, 2 when it is not
# JSON. JSON::PP ships with perl itself, so this needs no jq/php on the host.
PACKAGIST_LISTS_VERSION=perl -MJSON::PP -0777 -ne 'my $$d = eval { decode_json($$_) } or exit 2; \
	exit((grep { ($$_->{version} // "") eq $$ENV{V} } @{ $$d->{packages}{$$ENV{P}} || [] }) ? 0 : 1)'
# GH_REPO carries its double quotes as part of the VALUE (harmless in a recipe, where the shell
# strips them again); the JSON payload below is assembled by make itself, so they come off here.
PACKAGIST_REPOSITORY_URL=$(subst ",,$(GH_REPO))
# The request body of the update API. It carries no credentials - those travel in an
# Authorization header - which is why `make packagist_dry_run` can verify the exact payload the
# real publish sends without holding a single secret.
PACKAGIST_UPDATE_PAYLOAD={"repository":{"url":"$(PACKAGIST_REPOSITORY_URL)"}}

# The release tag under verification. Left EMPTY locally, where `check_version_agreement` derives
# it from HEAD instead (and finds none on an ordinary branch checkout); the release workflow sets
# it to ${{ github.ref_name }}, which a tag-triggered run always has, so the tag/version agreement
# can never be skipped there.
RELEASE_TAG?=

# `make` with no target prints the help listing.
.DEFAULT_GOAL := help

# Define colors globally (reused for [INFO]/[SUCCESS]/[WARN]/[ERROR] log lines in recipes)
BLUE   := \033[1;34m
GREEN  := \033[0;32m
YELLOW := \033[1;33m
RED    := \033[0;31m
NC     := \033[0m

########################################################
#       ONDEWO Standard Make Targets
########################################################

setup_developer_environment_locally: update_submodules install_dependencies install_dev_tools install_precommit_hooks ## Ready a fresh laptop: submodules, composer dependencies, dev tools and pre-commit hooks

install_precommit_hooks: ## Installs pre-commit hooks and sets them up for the ondewo-sip-client-php repo
	pre-commit install
	pre-commit install --hook-type commit-msg

precommit_hooks_run_all_files: release_tools ## Runs all pre-commit hooks on all files and not just the changed ones
	${PRECOMMIT_CMD} run --all-files

help: ## Print usage info about help targets
	# (first comment after target starting with double hashes ##)
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-40s\033[0m %s\n", $$1, $$2}'

makefile_chapters: ## Shows all sections of Makefile
	@echo `cat Makefile| grep "########################################################" -A 1 | grep -v "########################################################"`

TEST: ## Prints some important variables
	@echo "Client Version: \t ${ONDEWO_SIP_VERSION}"
	@echo "API Pin: \t\t ${ONDEWO_SIP_API_GIT_BRANCH}"
	@echo "Compiler Pin: \t\t ${ONDEWO_PROTO_COMPILER_GIT_BRANCH}"
	@echo "Compiler Image: \t ${PROTO_COMPILER_IMAGE}"
	@echo "GH Token: \t\t $(if $(filter-out ENTER_YOUR_TOKEN_HERE,$(GITHUB_GH_TOKEN)),<set>,<unset>)"
	@echo "Packagist Package: \t ${PACKAGIST_PACKAGE}"
	@echo "Packagist User: \t $(if $(filter-out ENTER_HERE_YOUR_PACKAGIST_USERNAME,$(PACKAGIST_USERNAME)),<set>,<unset>)"
	@echo "Packagist Token: \t $(if $(filter-out ENTER_HERE_YOUR_PACKAGIST_API_TOKEN,$(PACKAGIST_API_TOKEN)),<set>,<unset>)"
	@echo "Release Notes: \n \n$(CURRENT_RELEASE_NOTES)"

########################################################
#       Repo Specific Make Targets
########################################################
#		Build

build: checkout_defined_submodule_versions build_compiler generate_ondewo_protos update_composer_version ## Build the client: submodules -> compiler image -> stubs -> version bump
	@echo "$(GREEN)[SUCCESS]$(NC) ondewo-sip-client-php ${ONDEWO_SIP_VERSION} built"

build_compiler: ## Build the ondewo-php-proto-compiler docker image from the submodule, tagged with its version
	@echo "$(BLUE)[INFO]$(NC) Building ${PROTO_COMPILER_IMAGE} from ${ONDEWO_PROTO_COMPILER_DIR}/php ..."
	docker build --quiet -t ${PROTO_COMPILER_IMAGE} ${ONDEWO_PROTO_COMPILER_DIR}/php
	@echo "$(GREEN)[SUCCESS]$(NC) ${PROTO_COMPILER_IMAGE} built"

# NOTE: no `-it`. It breaks every non-interactive caller (CI, `make release`) with
#	"cannot attach stdin to a TTY-enabled container because stdin is not a terminal".
#	-it belongs only on the `--entrypoint /bin/bash` debug command documented in the README.
# NOTE: the container runs as the INVOKING USER (--user), so everything it writes is already ours -
#	no root-owned output, no `sudo chown` afterwards. The image keeps its working files under the
#	root-owned /image-data, so three of them are moved to /tmp: TEMP_SRC_DIRECTORY (the compile
#	scratch copy), COMPOSER_HOME, and COMPOSER_CACHE_READ_ONLY=1, which lets composer READ the
#	cache the image pre-warmed for its offline resolution without needing to write to it.
# NOTE: the repository root is NOT mounted. The input volume gets exactly the two things the image
#	reads - the proto root (${ONDEWO_SIP_API_DIR}, read-only) and this package's composer.json,
#	which it merges with its own defaults - and the output volume is a scratch directory holding a
#	copy of auth/ (the image adds auth/ to the shipped classmap only when it finds it there). src/
#	and the merged composer.json are copied back from there; the compiler's vendor/ and --no-dev
#	composer.lock are not (both are gitignored, and `make install_dependencies` resolves its own).
#	src/ is replaced wholesale, so a renamed or deleted proto leaves no orphaned stub behind.
# NOTE: hand-written PHP belongs in auth/ at the repository root, NEVER in src/. src/ is
#	compiler-owned and wiped on every run.
generate_ondewo_protos: ## Generate the PHP gRPC client stubs from the .proto definitions into src/
	@test -d ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} \
		|| { echo "$(RED)[ERROR]$(NC) '${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR}' not found - run 'make update_submodules' first"; exit 1; }
	@echo "$(BLUE)[INFO]$(NC) Generating PHP stubs from ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} with ${PROTO_COMPILER_IMAGE} ..."
	rm -rf ${PROTO_COMPILER_WORK_DIR}
	mkdir -p ${PROTO_COMPILER_WORK_DIR}/output
	cp -R auth ${PROTO_COMPILER_WORK_DIR}/output/auth
	docker run --rm --user $$(id -u):$$(id -g) \
		-e HOME=/tmp -e TEMP_SRC_DIRECTORY=/tmp/compile -e COMPOSER_HOME=/tmp/composer-home -e COMPOSER_CACHE_READ_ONLY=1 \
		-v "$(CURDIR)/${ONDEWO_SIP_API_DIR}":/input-volume/${ONDEWO_SIP_API_DIR}:ro \
		-v "$(CURDIR)/composer.json":/input-volume/composer.json:ro \
		-v "$(CURDIR)/${PROTO_COMPILER_WORK_DIR}/output":/output-volume \
		${PROTO_COMPILER_IMAGE} ${ONDEWO_SIP_API_DIR} ${PROTOS_TARGET_SUBDIR}
	@test -d ${PROTO_COMPILER_WORK_DIR}/output/src \
		|| { echo "$(RED)[ERROR]$(NC) the compiler wrote no src/ to ${PROTO_COMPILER_WORK_DIR}/output"; exit 1; }
	rm -rf src
	cp -R ${PROTO_COMPILER_WORK_DIR}/output/src src
	cp ${PROTO_COMPILER_WORK_DIR}/output/composer.json composer.json
	rm -rf ${PROTO_COMPILER_WORK_DIR}
	@echo "$(GREEN)[SUCCESS]$(NC) PHP stubs generated in src/"

update_composer_version: ## Set ONDEWO_SIP_VERSION as the `version` field of composer.json
	@perl -i -pe 's/^(\s*"version":\s*")[0-9]+\.[0-9]+\.[0-9]+(")/$${1}${ONDEWO_SIP_VERSION}$${2}/' composer.json
	@echo "$(GREEN)[SUCCESS]$(NC) composer.json version set to ${ONDEWO_SIP_VERSION}"

php_toolchain: ## Build the pinned php/composer image - a no-op when the host has php and composer
ifneq (${HOST_HAS_PHP},yes)
	@docker build --quiet -f ${TOOLS_DIR}/php.Dockerfile -t ${PHP_TOOLCHAIN_IMAGE} ${TOOLS_DIR} > /dev/null
	@echo "$(BLUE)[INFO]$(NC) no php/composer on this host - running them from ${PHP_TOOLCHAIN_IMAGE}"
endif

release_tools: ## Build the pinned gh/pre-commit image - a no-op when the host has both gh and pre-commit
ifneq (${HOST_HAS_GH}${HOST_HAS_PRECOMMIT},yesyes)
	@docker build --quiet -f ${TOOLS_DIR}/release-tools.Dockerfile -t ${RELEASE_TOOLS_IMAGE} ${TOOLS_DIR} > /dev/null
	@echo "$(BLUE)[INFO]$(NC) gh and/or pre-commit missing on this host - running them from ${RELEASE_TOOLS_IMAGE}"
endif

install_dependencies: php_toolchain ## Resolve and install the composer dependencies of the package (needs network)
# composer.json declares autoload.classmap ["src/"], and `composer update` aborts with
#	'Could not scan for classes inside "src/" which does not appear to be a file nor a folder'
#	when the stubs have not been generated yet. An empty src/ is harmless - it is compiler-owned
#	and wiped on the next generation run, and git does not track empty directories.
	@mkdir -p src
	${COMPOSER_CMD} update --prefer-dist --no-interaction --no-progress

install_dev_tools: php_toolchain ## Resolve and install PHPUnit + the coverage gate into tools/vendor (needs network)
# No lock file is committed for tools/: it would pin ONE phpunit major, and this repository is
#	tested on php 8.1 (phpunit 10) through 8.4 (phpunit 11) - the constraint has to be re-resolved
#	per interpreter, which is what `update` does and `install` cannot.
	${COMPOSER_CMD} update --working-dir=${TOOLS_DIR} --prefer-dist --no-interaction --no-progress

clean: ## Remove the composer artifacts, the dev tools and the generated stubs
	rm -rf vendor composer.lock src ${TOOLS_DIR}/vendor ${TOOLS_DIR}/composer.lock build .phpunit.cache

########################################################
#		Test

test: composer_validate packagist_dry_run lint_php check_build coverage ## Validate the manifest, verify the packaging path, syntax-check hand-written PHP, check the stubs against the api submodule and run the covered PHPUnit suite

# What .github/workflows/ci.yml runs, verbatim. It deliberately leaves `check_build` out: that
# target compares src/ against the ondewo-sip-api submodule, and CI checks out NO submodules -
# the stubs are committed, so what CI has to prove is that the COMMITTED tree builds and passes
# its tests. tests/Generated/* carries the submodule-free half of the same assertion (every
# generated class loads, every descriptor initialises, every service and rpc the descriptors declare
# has its stub) and fails - never skips - when src/ is missing. CI's `stubs` job runs check_build.
ci: composer_validate packagist_dry_run lint_php coverage ## Run the CI gate locally (no submodules, no docker)
	@echo "$(GREEN)[SUCCESS]$(NC) CI gate passed"

composer_validate: php_toolchain ## Validate composer.json
# Deliberately NOT --strict: the `version` field the release targets bump is a strict-mode
# warning that --strict turns into a failure (rc 1), exactly as in the compiler image.
# `composer_validate_strict` below runs --strict anyway, with the deliberate warnings enumerated,
# so a NEW warning still fails the build. Its output is printed only when it FAILS: on success it
# is the three deliberate warnings, which would otherwise read as warnings in every build log.
	@mkdir -p build
	@${COMPOSER_CMD} validate --no-check-publish --no-ansi --no-interaction > build/composer-validate.log 2>&1 \
		|| { cat build/composer-validate.log; echo "$(RED)[ERROR]$(NC) composer validate failed"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) composer.json is valid (full output: build/composer-validate.log)"

lint_php: php_toolchain ## Syntax-check every hand-written PHP file with `php -l` (src/ is generated and skipped)
	@for dir in auth tests; do \
		[ -d "$$dir" ] || continue; \
		find "$$dir" -type f -name '*.php' | while IFS= read -r f; do \
			${PHP_CMD} -l "$$f" > /dev/null || { echo "$(RED)[ERROR]$(NC) php -l failed: $$f"; exit 1; }; \
		done || exit 1; \
	done
	@echo "$(GREEN)[SUCCESS]$(NC) hand-written PHP sources are syntactically valid"

check_build: ## Fails if any .proto or rpc of the API submodule has no generated PHP code (or a stub has no rpc)
	@test -d src \
		|| { echo "$(RED)[ERROR]$(NC) src/ is missing - run 'make generate_ondewo_protos' first"; exit 1; }
	@test -d ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} \
		|| { echo "$(RED)[ERROR]$(NC) '${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR}' not found - run 'make update_submodules' first"; exit 1; }
# protoc's php generator names a file after the UpperCamel form of the .proto basename, split on
# '_' AND '-' (ai_services.proto -> AiServices.php, speech-to-text.proto -> SpeechToText.php), so
# the basename is camel-cased the same way before it is looked up.
	@find ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} -type f -name '*.proto' \
		| while IFS= read -r proto; do \
			camel=`basename "$$proto" .proto | awk -F'[_-]' '{s=""; for(i=1;i<=NF;i++){s = s toupper(substr($$i,1,1)) substr($$i,2)}; print s}'`; \
			find src -type f -name "$$camel.php" | grep -q . \
				|| { echo "$(RED)[ERROR]$(NC) No PHP code generated for $$proto (expected a $$camel.php)"; exit 1; }; \
		done || exit 1
	@echo "$(GREEN)[SUCCESS]$(NC) every .proto has generated PHP code"
# The file-level loop above cannot see a stale stub: an RPC added to, renamed in or removed from a
# .proto without regenerating still leaves a file of the right name. Compare the method paths.
	@perl tools/check-generated-rpcs.pl ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} src \
		|| { echo "$(RED)[ERROR]$(NC) src/ is stale against ${ONDEWO_SIP_API_DIR} - run 'make generate_ondewo_protos'"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) the generated stubs serve exactly the RPCs of ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR}"

# NOTE: no `[ -x ... ] || skip` guard. A missing tool chain or a missing test suite is a RED
#	build, not a green one - that guard is exactly what let this repository's CI pass while it
#	held no code at all.
phpunit: php_toolchain ## Run the PHPUnit suite
	@test -x ${PHPUNIT} \
		|| { echo "$(RED)[ERROR]$(NC) '${PHPUNIT}' is missing - run 'make install_dev_tools'"; exit 1; }
	@test -f vendor/autoload.php \
		|| { echo "$(RED)[ERROR]$(NC) 'vendor/autoload.php' is missing - run 'make install_dependencies'"; exit 1; }
	${PHP_CMD} ${PHPUNIT} --colors=never

coverage: php_toolchain ## Run the PHPUnit suite with coverage and fail below COVERAGE_MIN% of the hand-written code
	@test -x ${PHPUNIT} \
		|| { echo "$(RED)[ERROR]$(NC) '${PHPUNIT}' is missing - run 'make install_dev_tools'"; exit 1; }
	@test -f vendor/autoload.php \
		|| { echo "$(RED)[ERROR]$(NC) 'vendor/autoload.php' is missing - run 'make install_dependencies'"; exit 1; }
# --fail-on-skipped/--fail-on-incomplete: a test that quietly skips itself is the green-by-omission
#	failure mode this suite exists to rule out.
	${PHP_CMD} ${PHP_COVERAGE_FLAGS} ${PHPUNIT} --colors=never --fail-on-skipped --fail-on-incomplete \
		--coverage-clover ${CLOVER_REPORT} --coverage-text
	@test -f ${CLOVER_REPORT} \
		|| { echo "$(RED)[ERROR]$(NC) no coverage report at '${CLOVER_REPORT}' - is a coverage driver (pcov/xdebug) enabled?"; exit 1; }
	${PHP_CMD} ${COVERAGE_CHECK} ${CLOVER_REPORT} ${COVERAGE_MIN}

########################################################
#		Submodules

update_submodules: ## Initialize and update all submodules
	@echo "$(BLUE)[INFO]$(NC) START initializing submodules ..."
	git submodule update --init --recursive
	@echo "$(GREEN)[SUCCESS]$(NC) DONE initializing submodules"

checkout_defined_submodule_versions: update_submodules ## Check out the submodule versions pinned at the top of this Makefile
	@echo "$(BLUE)[INFO]$(NC) START checking out submodules ..."
	git -C ${ONDEWO_SIP_API_DIR} fetch --all
	git -C ${ONDEWO_SIP_API_DIR} checkout ${ONDEWO_SIP_API_GIT_BRANCH}
	git -C ${ONDEWO_PROTO_COMPILER_DIR} fetch --all
	git -C ${ONDEWO_PROTO_COMPILER_DIR} checkout ${ONDEWO_PROTO_COMPILER_GIT_BRANCH}
	@echo "$(GREEN)[SUCCESS]$(NC) DONE checking out submodules"

########################################################
#		Release

release: ## Automate the entire release process (preflight -> build -> tests -> commit -> tag -> publish -> GitHub release -> verify)
	@echo "$(BLUE)[INFO]$(NC) Start Release"
# FIRST, before anything is built, committed, branched, tagged or pushed, and read-only: every
# credential is present AND accepted, the version is still free on origin and on Packagist, the
# RELEASE.md entry exists, the host has its tools. A release that cannot finish has to fail while
# it is still a no-op - a pushed tag cannot be taken back, and `spc` refuses every retry after one.
	$(MAKE) release_preflight
# Every step from here to the tag needs NO credential, so none of them gets one.
	$(WITHOUT_CREDENTIALS) $(MAKE) build
# No leading `-`: a failing hook (markdownlint over the RELEASE.md entry, a whitespace fixer that
# had to rewrite a file) stops the release here, before the tree it complains about is tagged.
	$(WITHOUT_CREDENTIALS) $(MAKE) precommit_hooks_run_all_files
# The whole suite (manifest, packaging dry run, php -l, stub inventory incl. the rpc drift check
# against the api submodule, PHPUnit with the coverage gate) runs against the freshly generated
# tree BEFORE anything is committed, branched, tagged or published.
	$(WITHOUT_CREDENTIALS) $(MAKE) install_dependencies install_dev_tools test
	git status
	git add src
	git add composer.json
	git add Makefile
	git add README.md
	git add RELEASE.md
# auth/ is the hand-written surface (bearer credentials, Keycloak token provider). It is
# top-level and NOT covered by `git add src`, so leaving it out means a fix written there is
# published from the tag without ever reaching the repository.
	git add auth
# tests/ and tools/ are not part of the published classmap, but a regression test written alongside
# a fix must reach the repository or CI never runs it. Every path here exists, so no leading `-`:
# one missing pathspec makes git reject the WHOLE add, which the `-` used to hide.
	git add tests tools phpunit.xml.dist
	git add ${ONDEWO_PROTO_COMPILER_DIR}
	git add ${ONDEWO_SIP_API_DIR}
	git status
# Commit only when something is staged, but never ignore a FAILED commit (no git identity, a
# broken index): a `-` here would let the release tag and publish the previous commit.
	git diff --cached --quiet || git commit --no-verify -m "PREPARING FOR RELEASE ${ONDEWO_SIP_VERSION}"
# Packagist has no upload: it serves the tree of a git TAG, so - unlike the clients whose registry
# takes an artifact - push, release branch and tag have to come BEFORE the publish step.
	$(WITHOUT_CREDENTIALS) git push
	$(WITHOUT_CREDENTIALS) $(MAKE) create_release_branch
	$(WITHOUT_CREDENTIALS) $(MAKE) create_release_tag
# Tell Packagist to crawl the tag now (release.yml pings too, after re-testing the tag; the ping is
# idempotent).
	$(MAKE) publish
	$(MAKE) push_to_gh
# Read-only: the tag is on origin, the GitHub release has notes, the tag's release.yml run is
# green and Packagist SERVES the version. Exits non-zero otherwise - "Release Finished" is only
# printed for a release somebody can actually `composer require`.
	$(MAKE) release_verify
	@echo "$(GREEN)[SUCCESS]$(NC) Release Finished - ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} is on Packagist"

create_release_branch: ## Create Release Branch and push it to origin
	git checkout -b "release/${ONDEWO_SIP_VERSION}"
	git push -u origin "release/${ONDEWO_SIP_VERSION}"

create_release_tag: ## Create Release Tag and push it to origin
	git tag -a ${ONDEWO_SIP_VERSION} -m "release/${ONDEWO_SIP_VERSION}"
	git push origin ${ONDEWO_SIP_VERSION}

########################################################
#		PREFLIGHT AND VERIFY (read-only)

release_preflight: ## Read-only: fail unless the release can finish (tools, version free, notes, valid GitHub + Packagist credentials)
	@$(LOAD_DEVOPS_CREDENTIALS); \
	$(MAKE) --no-print-directory release_preflight_checks

release_preflight_checks: check_host_tools check_no_devops_clone_in_repo spc check_release_notes check_gh_credentials check_gh_push_permission check_packagist_credentials check_packagist_auth ## The checks of release_preflight (credentials from the environment only)
	@echo "$(GREEN)[SUCCESS]$(NC) preflight passed - ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} can be released"

# Only what the release itself runs on the host. php/composer, gh and pre-commit are not required:
# each comes from a pinned image when the host lacks it (php_toolchain, release_tools).
check_host_tools: ## Fail unless docker, git, perl (with JSON::PP) and curl are available
	@for tool in docker git perl curl; do \
		command -v $$tool > /dev/null || { echo "$(RED)[ERROR]$(NC) '$$tool' is not installed - the release needs docker, make, git, perl and curl"; exit 1; }; \
	done
	@perl -MJSON::PP -e 1 2> /dev/null || { echo "$(RED)[ERROR]$(NC) perl lacks the core module JSON::PP (Debian: apt-get install perl)"; exit 1; }
	@docker version --format '{{.Server.Version}}' > /dev/null 2>&1 || { echo "$(RED)[ERROR]$(NC) the docker daemon is not reachable"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) host tools present - php/composer: $(if ${HOST_HAS_PHP},host,docker), gh: $(if ${HOST_HAS_GH},host,docker), pre-commit: $(if ${HOST_HAS_PRECOMMIT},host,docker)"

# The release mounts the repository root into containers (php/composer, gh and pre-commit when the
# host lacks them). A devops-accounts clone in there would be readable by all of them.
check_no_devops_clone_in_repo: ## Fail if a devops-accounts clone sits inside this repository
	@if [ -e "${DEVOPS_ACCOUNT_GIT}" ]; then \
		echo "$(RED)[ERROR]$(NC) '$(CURDIR)/${DEVOPS_ACCOUNT_GIT}' exists - delete it; 'make ondewo_release' never clones into the repository"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) no devops-accounts clone inside the repository"

# Proves the token is VALID and may push here - presence alone (the old check) let an expired token
# through to the very end of the release, after the tag was already on origin.
check_gh_push_permission: release_tools ## Read-only: fail unless GITHUB_GH_TOKEN is accepted by GitHub and may push to this repository
	@perm=`GH_TOKEN="$${GITHUB_GH_TOKEN}" ${GH_CMD} api repos/${GH_REPO_SLUG} --jq .permissions.push` \
		|| { echo "$(RED)[ERROR]$(NC) GitHub rejected GITHUB_GH_TOKEN (see gh's message above)"; exit 1; }; \
	if [ "$$perm" != "true" ]; then \
		echo "$(RED)[ERROR]$(NC) GITHUB_GH_TOKEN is valid but has no push permission on ${GH_REPO_SLUG} (permissions.push=$$perm)"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) GITHUB_GH_TOKEN is accepted and may push to ${GH_REPO_SLUG}"

# What this proves, without changing anything on Packagist:
#   1. The update API AUTHENTICATES the pair. The probe names a repository url that matches no
#      package, so Packagist checks the credentials (403 "Missing or invalid username/apiToken"
#      when they are wrong) and then answers 404 "Could not find a package that matches this
#      request" - nothing is crawled, nothing is queued.
#   2. The authenticated user MAINTAINS ${PACKAGIST_PACKAGE}: the public package JSON lists it among
#      the maintainers, and the update API only crawls a package for one of its maintainers.
# Together that is exactly what the real ping in `make publish` needs. The credentials travel the
# same way as there (an Authorization header on curl's STDIN, never on argv) and are never printed.
PACKAGIST_AUTH_PROBE_PAYLOAD={"repository":{"url":"https://github.com/ondewo/ondewo-sip-client-php-release-preflight-probe"}}
check_packagist_auth: check_packagist_credentials ## Read-only: fail unless Packagist accepts PACKAGIST_USERNAME/PACKAGIST_API_TOKEN and that user maintains the package
	@mkdir -p build
	@code=`printf 'header = "Authorization: Bearer %s:%s"\n' "$${PACKAGIST_USERNAME}" "$${PACKAGIST_API_TOKEN}" \
		| curl --silent --show-error --config - \
		--output build/packagist-auth-probe.json --write-out '%{http_code}' \
		-X POST -H 'Content-Type: application/json' \
		-d '$(PACKAGIST_AUTH_PROBE_PAYLOAD)' \
		"${PACKAGIST_UPDATE_API}"` || { echo "$(RED)[ERROR]$(NC) could not reach ${PACKAGIST_UPDATE_API}"; exit 1; }; \
	if [ "$$code" = "403" ] || [ "$$code" = "401" ]; then \
		echo "$(RED)[ERROR]$(NC) Packagist rejected PACKAGIST_USERNAME/PACKAGIST_API_TOKEN (HTTP $$code)"; exit 1; fi; \
	if [ "$$code" != "404" ] || ! grep -q 'Could not find a package' build/packagist-auth-probe.json; then \
		echo "$(RED)[ERROR]$(NC) unexpected answer from the Packagist auth probe (HTTP $$code):"; cat build/packagist-auth-probe.json; echo; exit 1; fi
	@curl --silent --show-error --fail --output build/packagist-package.json "${PACKAGIST_PACKAGE_JSON_URL}" \
		|| { echo "$(RED)[ERROR]$(NC) could not read ${PACKAGIST_PACKAGE_JSON_URL}"; exit 1; }
	@perl -MJSON::PP -0777 -ne 'my $$d = decode_json($$_); exit((grep { $$_->{name} eq $$ENV{PACKAGIST_USERNAME} } @{ $$d->{package}{maintainers} || [] }) ? 0 : 1)' build/packagist-package.json \
		|| { echo "$(RED)[ERROR]$(NC) the PACKAGIST_USERNAME account is not a maintainer of ${PACKAGIST_PACKAGE} - its ping would be refused"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist accepts the credentials and the account maintains ${PACKAGIST_PACKAGE}"

release_verify: ## Read-only, after a release: fail unless the tag is on origin, the GitHub release has notes, the tag's release.yml run succeeded and Packagist serves the version
	@$(LOAD_DEVOPS_CREDENTIALS); \
	$(MAKE) --no-print-directory release_verify_checks

release_verify_checks: check_gh_credentials release_tools ## The checks of release_verify (credentials from the environment only)
	@rc=0; git ls-remote --exit-code --tags origin "refs/tags/${ONDEWO_SIP_VERSION}" > /dev/null || rc=$$?; \
	if [ $$rc -eq 2 ]; then echo "$(RED)[ERROR]$(NC) tag ${ONDEWO_SIP_VERSION} is not on origin"; exit 1; fi; \
	if [ $$rc -ne 0 ]; then echo "$(RED)[ERROR]$(NC) git ls-remote origin failed (exit $$rc)"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) tag ${ONDEWO_SIP_VERSION} is on origin"
	@body=`GH_TOKEN="$${GITHUB_GH_TOKEN}" ${GH_CMD} release view "${ONDEWO_SIP_VERSION}" --repo ${GH_REPO_SLUG} --json body --jq .body` \
		|| { echo "$(RED)[ERROR]$(NC) gh release view ${ONDEWO_SIP_VERSION} failed - no such GitHub release, or GitHub rejected GITHUB_GH_TOKEN (see gh's message above)"; exit 1; }; \
	if [ -z "`printf '%s' "$$body" | tr -d '[:space:]'`" ]; then \
		echo "$(RED)[ERROR]$(NC) the GitHub release ${ONDEWO_SIP_VERSION} has an EMPTY body"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) the GitHub release ${ONDEWO_SIP_VERSION} has release notes"
# The run that release.yml starts for the tag re-tests the tagged tree and pings Packagist. It can
# take a few seconds to be listed at all, and minutes to finish.
	@i=0; while :; do \
		i=$$((i + 1)); \
		run=`GH_TOKEN="$${GITHUB_GH_TOKEN}" ${GH_CMD} run list --repo ${GH_REPO_SLUG} --workflow release.yml --branch "${ONDEWO_SIP_VERSION}" --event push --limit 1 \
			--json databaseId,status,conclusion --jq '.[] | "\(.databaseId) \(.status) \(.conclusion)"'` \
			|| { echo "$(RED)[ERROR]$(NC) gh run list failed"; exit 1; }; \
		set -- $$run; \
		if [ "$$2" = "completed" ]; then \
			[ "$$3" = "success" ] && { echo "$(GREEN)[SUCCESS]$(NC) release.yml run $$1 for ${ONDEWO_SIP_VERSION} succeeded"; break; }; \
			echo "$(RED)[ERROR]$(NC) release.yml run $$1 for ${ONDEWO_SIP_VERSION} ended '$$3' - https://github.com/${GH_REPO_SLUG}/actions/runs/$$1"; exit 1; \
		fi; \
		if [ $$i -ge ${VERIFY_ATTEMPTS} ]; then \
			echo "$(RED)[ERROR]$(NC) release.yml for ${ONDEWO_SIP_VERSION} not finished after ${VERIFY_ATTEMPTS} x ${VERIFY_INTERVAL_S}s (run: $${1:-none listed}, status: $${2:-none})"; exit 1; fi; \
		echo "$(BLUE)[INFO]$(NC) release.yml for ${ONDEWO_SIP_VERSION}: $${2:-not listed yet} - attempt $$i/${VERIFY_ATTEMPTS}, retrying in ${VERIFY_INTERVAL_S}s"; \
		sleep ${VERIFY_INTERVAL_S}; \
	done
	@mkdir -p build
	@i=0; while :; do \
		i=$$((i + 1)); \
		if curl --silent --show-error --fail --output build/packagist-p2.json "${PACKAGIST_P2_URL}" \
			&& V='${ONDEWO_SIP_VERSION}' P='${PACKAGIST_PACKAGE}' ${PACKAGIST_LISTS_VERSION} build/packagist-p2.json; then \
			echo "$(GREEN)[SUCCESS]$(NC) Packagist serves ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} (${PACKAGIST_P2_URL})"; break; fi; \
		if [ $$i -ge ${VERIFY_ATTEMPTS} ]; then \
			echo "$(RED)[ERROR]$(NC) ${PACKAGIST_P2_URL} still does not list ${ONDEWO_SIP_VERSION} after ${VERIFY_ATTEMPTS} x ${VERIFY_INTERVAL_S}s"; exit 1; fi; \
		echo "$(BLUE)[INFO]$(NC) Packagist does not list ${ONDEWO_SIP_VERSION} yet - attempt $$i/${VERIFY_ATTEMPTS}, retrying in ${VERIFY_INTERVAL_S}s"; \
		sleep ${VERIFY_INTERVAL_S}; \
	done

########################################################
#		GITHUB

push_to_gh: build_gh_release ## Create the GitHub release
	@echo "$(GREEN)[SUCCESS]$(NC) Released to GitHub"

# Never prints the token, only whether it is usable. The EMPTY string has to be rejected next to
# the placeholder: an unset GitHub secret and `make release GITHUB_GH_TOKEN=` both expand to it.
# Whether GitHub ACCEPTS it is check_gh_push_permission's job (release_preflight runs both).
check_gh_credentials: ## Fail unless GITHUB_GH_TOKEN is set
	@if [ -z "$${GITHUB_GH_TOKEN}" ] || [ "$${GITHUB_GH_TOKEN}" = "ENTER_YOUR_TOKEN_HERE" ]; then \
		echo "$(RED)[ERROR]$(NC) GITHUB_GH_TOKEN is not set - create one at https://github.com/settings/tokens (devops-accounts: account_github.env)"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) GITHUB_GH_TOKEN is set"

# `gh release create -n ""` succeeds and publishes an EMPTY release, so a forgotten RELEASE.md
# entry - or a heading whose wording drifted away from what the CURRENT_RELEASE_NOTES flip-flop
# greps for - is otherwise only noticed by whoever reads the release page afterwards. This asserts
# the slice's BODY: the slice always holds the heading and the ***** separator, so "the slice is
# not empty" also passed for a heading with nothing under it.
check_release_notes: ## Assert RELEASE.md has a non-empty '## Release ONDEWO SIP PHP Client <ONDEWO_SIP_VERSION>' entry
	@notes="$(CURRENT_RELEASE_NOTES)"; \
	body=`printf '%s\n' "$$notes" | sed '1d' | grep -v '^\*\{5\}' | tr -d '[:space:]'`; \
	if [ -z "$$body" ]; then \
		echo "$(RED)[ERROR]$(NC) RELEASE.md has no non-empty '## Release ONDEWO SIP PHP Client ${ONDEWO_SIP_VERSION}' entry (the heading must match exactly, nothing after the version)"; \
		echo "        The GitHub release would be created with empty notes - add the entry first."; \
		exit 1; \
	fi; \
	echo "$(GREEN)[SUCCESS]$(NC) RELEASE.md has release notes for ${ONDEWO_SIP_VERSION}"

# The notes go through a FILE in build/ rather than `-n "<notes>"`, so a backtick or a `$` in them
# is never shell-evaluated on the way, and the docker fallback of gh reads the same file.
build_gh_release: check_gh_credentials check_release_notes release_tools ## Create the GitHub release with the RELEASE.md notes (refuses empty notes)
	@mkdir -p build
	@printf '%s\n' "$(CURRENT_RELEASE_NOTES)" > build/release-notes.md
	GH_TOKEN="$${GITHUB_GH_TOKEN}" ${GH_CMD} release create "${ONDEWO_SIP_VERSION}" --repo ${GH_REPO_SLUG} \
		--notes-file build/release-notes.md --title "Release ${ONDEWO_SIP_VERSION}"

########################################################
#		PACKAGIST

# The equivalent of `twine upload` (python) or `npm publish` (typescript) - except that Packagist
# accepts no artifact at all. It reads the git tag straight off GitHub, so the only thing left to
# do is (1) prove the tagged tree is a publishable composer package and (2) ask Packagist to crawl
# it now instead of at its next scheduled pass.
# check_packagist_credentials runs FIRST so a missing token fails in a second rather than after
# the whole validation pass. The dry run (composer, php) gets no credential; only the ping does.
publish: check_packagist_credentials ## Validate the package and tell Packagist to crawl the new tag (the PHP equivalent of an upload)
	$(WITHOUT_CREDENTIALS) $(MAKE) packagist_dry_run
	$(MAKE) packagist_update
	@echo "$(GREEN)[SUCCESS]$(NC) ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} published - https://packagist.org/packages/${PACKAGIST_PACKAGE}"

# Everything `publish` can check WITHOUT a credential. Wired into `make ci` (and therefore into
# .github/workflows/ci.yml) so the packaging path is exercised on every single push, not for the
# first time on release day.
packagist_dry_run: composer_validate composer_validate_strict check_version_agreement check_packagist_payload ## Credential-free verification of the whole packaging path (runs in CI on every push)
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist dry run passed - ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} is publishable"

# `composer validate --strict` reports exactly three warnings here, all of them deliberate and
# permanent:
#   * "The version field is present"        - composer.json's `version` is what
#     `make update_composer_version` writes and what `spc` (Test 3) refuses to release without.
#     Packagist derives the version from the TAG, but the fleet keeps the field so the version is
#     greppable in the tree; `check_version_agreement` below is what keeps the two from drifting.
#   * the two exact version constraints     - google/protobuf and grpc/grpc MUST be pinned to the
#     exact versions the compiler image ships (README rule 2): the image resolves the merged
#     manifest with the network OFF, from a cache warmed at image-build time, so a range that
#     resolves to anything else takes `make generate_ondewo_protos` down.
# So --strict can never be run bare here (it exits 1 on a warning). This target runs it anyway and
# fails on any warning that is NOT one of those three - a newly introduced warning is a real
# regression and would otherwise drown in `composer validate`'s output.
# --no-check-lock: composer.lock is deliberately NOT committed here (see .gitignore - this is a
# library, and the compiler image writes a --no-dev lock of its own). CI validates before it ever
# installs, so there is no lock to check; a developer who runs this after `make install_dependencies`
# would otherwise fail on a purely local artifact that is never published.
composer_validate_strict: php_toolchain ## Run `composer validate --strict` and fail on any warning beyond the three deliberate ones
	@mkdir -p build
	@${COMPOSER_CMD} validate --strict --no-check-lock --no-ansi --no-interaction > build/composer-validate-strict.log 2>&1 || true
	@grep -q "is valid" build/composer-validate-strict.log \
		|| { cat build/composer-validate-strict.log; echo "$(RED)[ERROR]$(NC) composer.json is INVALID - see the output above"; exit 1; }
	@grep '^- ' build/composer-validate-strict.log \
		| grep -v -e "The version field is present" \
		          -e "require.google/protobuf : exact version constraints" \
		          -e "require.grpc/grpc : exact version constraints" \
		> build/composer-validate-strict.unexpected || true
	@if [ -s build/composer-validate-strict.unexpected ]; then \
		echo "$(RED)[ERROR]$(NC) composer validate --strict reported warnings beyond the three deliberate ones:"; \
		cat build/composer-validate-strict.unexpected; \
		exit 1; \
	fi
	@echo "$(GREEN)[SUCCESS]$(NC) composer validate --strict: only the three deliberate warnings"

# Packagist resolves a version from the TAG NAME, while composer.json here also carries an
# explicit `version`. When those two disagree Packagist publishes the field's value under the
# tag's name - a release that installs as a version nobody tagged. This is the agreement check the
# fleet requires, and it also refuses a version with no RELEASE.md entry, because
# CURRENT_RELEASE_NOTES would then slice out nothing and `gh release create` would ship empty
# notes without complaining.
check_version_agreement: php_toolchain ## Fail unless ONDEWO_SIP_VERSION, composer.json, RELEASE.md and (when HEAD is a tag) the git tag all agree
	@name=`${PHP_CMD} -r 'echo json_decode(file_get_contents("composer.json"), true)["name"] ?? "";'`; \
	version=`${PHP_CMD} -r 'echo json_decode(file_get_contents("composer.json"), true)["version"] ?? "";'`; \
	if [ "$$name" != "${PACKAGIST_PACKAGE}" ]; then \
		echo "$(RED)[ERROR]$(NC) composer.json name is '$$name' but the Packagist package is '${PACKAGIST_PACKAGE}'"; exit 1; fi; \
	if [ "$$version" != "${ONDEWO_SIP_VERSION}" ]; then \
		echo "$(RED)[ERROR]$(NC) composer.json version is '$$version' but ONDEWO_SIP_VERSION is '${ONDEWO_SIP_VERSION}' - run 'make update_composer_version'"; exit 1; fi; \
	grep -qE '^#+ Release ONDEWO SIP PHP Client $(subst .,\.,${ONDEWO_SIP_VERSION})$$' RELEASE.md \
		|| { echo "$(RED)[ERROR]$(NC) RELEASE.md has no '## Release ONDEWO SIP PHP Client ${ONDEWO_SIP_VERSION}' entry - the GitHub release would ship empty notes"; exit 1; }; \
	tag="${RELEASE_TAG}"; \
	[ -n "$$tag" ] || tag=`git describe --exact-match --tags HEAD 2>/dev/null || true`; \
	if [ -z "$$tag" ]; then \
		echo "$(BLUE)[INFO]$(NC) HEAD is not a release tag - tag agreement not applicable (set RELEASE_TAG to force the check)"; \
	elif [ "$$tag" != "${ONDEWO_SIP_VERSION}" ]; then \
		echo "$(RED)[ERROR]$(NC) git tag '$$tag' does not match ONDEWO_SIP_VERSION '${ONDEWO_SIP_VERSION}' - Packagist would publish the tag under the wrong version"; exit 1; \
	else \
		echo "$(BLUE)[INFO]$(NC) git tag '$$tag' agrees with ONDEWO_SIP_VERSION"; \
	fi
	@echo "$(GREEN)[SUCCESS]$(NC) ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION}: version fields agree"

# The update API identifies the package by its VCS url, NOT by its composer name: ping the wrong
# url with valid credentials and Packagist answers 200 for a package that is not this one. So the
# payload is checked against composer.json's own support.source, which is what was submitted.
check_packagist_payload: php_toolchain ## Fail unless the Packagist update payload is well-formed JSON pointing at this repository
	@mkdir -p build
	@printf '%s\n' '$(PACKAGIST_UPDATE_PAYLOAD)' > build/packagist-update-payload.json
	@url=`${PHP_CMD} -r 'echo json_decode(file_get_contents("build/packagist-update-payload.json"), true)["repository"]["url"] ?? "";'`; \
	source=`${PHP_CMD} -r 'echo json_decode(file_get_contents("composer.json"), true)["support"]["source"] ?? "";'`; \
	if [ -z "$$url" ]; then \
		echo "$(RED)[ERROR]$(NC) the update payload is not valid JSON or carries no repository.url:"; \
		cat build/packagist-update-payload.json; exit 1; fi; \
	if [ "$$url" != "$$source" ]; then \
		echo "$(RED)[ERROR]$(NC) the update payload points at '$$url' but composer.json support.source is '$$source'"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist update payload points at ${PACKAGIST_REPOSITORY_URL}"

# Never prints either credential, only whether it is usable. Both the placeholder AND the empty
# string have to be rejected: an unset GitHub secret expands to the EMPTY string, so a check that
# only looked for the placeholder would let the release workflow post an unauthenticated ping and
# report success for a package that was never crawled.
check_packagist_credentials: ## Fail unless PACKAGIST_USERNAME and PACKAGIST_API_TOKEN are set
	@if [ -z "$${PACKAGIST_USERNAME}" ] || [ "$${PACKAGIST_USERNAME}" = "ENTER_HERE_YOUR_PACKAGIST_USERNAME" ]; then \
		echo "$(RED)[ERROR]$(NC) PACKAGIST_USERNAME is not set - it is the Packagist login name (devops-accounts: account_packagist.env, CI: the PACKAGIST_USERNAME secret)"; exit 1; fi
	@if [ -z "$${PACKAGIST_API_TOKEN}" ] || [ "$${PACKAGIST_API_TOKEN}" = "ENTER_HERE_YOUR_PACKAGIST_API_TOKEN" ]; then \
		echo "$(RED)[ERROR]$(NC) PACKAGIST_API_TOKEN is not set - create one at https://packagist.org/profile/ 'Show API token' (devops-accounts: account_packagist.env, CI: the PACKAGIST_API_TOKEN secret)"; exit 1; fi
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist credentials are set"

# Prefixed with @ so neither credential reaches the build log, and written against the shell
# variables of the ENVIRONMENT the recipe was handed rather than against $(PACKAGIST_API_TOKEN):
# should the @ ever be dropped, make then echoes the variable NAME instead of the token. --fail is deliberately NOT used - the http code is inspected by hand so a 403 is
# reported as "bad credentials" instead of curl's bare exit 22.
#
# THE CREDENTIALS ARE NOT IN THE URL. Packagist's ApiController::findUser() accepts three spellings
# - POST body parameters, ?username=&apiToken= query parameters, and an `Authorization: Bearer
# <username>:<apiToken>` header that takes precedence over the other two - and only the header keeps
# the token out of places that are not ours. The query parameter put it in /proc/<pid>/cmdline,
# which is world-readable, in the shell history of anyone who copied the command, and in the access
# log of every proxy on the way. The POST body is no use here: Symfony reads body parameters out of
# FORM encoding, and the body of this request is the JSON payload above.
#
# The header itself is fed to curl through `--config -` on STDIN rather than a `-H` argument,
# because a -H argument would land in the process table exactly like the query parameter did.
# printf is a shell builtin, so the only command line that ever holds the values is curl's - and
# curl's holds neither.
packagist_update: ## Ping the Packagist update API so it crawls the tags of this repository
	@mkdir -p build
	@echo "$(BLUE)[INFO]$(NC) Asking Packagist to crawl ${PACKAGIST_REPOSITORY_URL} ..."
# Packagist answers 202 Accepted on success - the crawl is queued, not finished - and 200 only on
# some paths. Both are success; the authoritative signal is status=success in the body. Demanding
# 200 alone reported a completed publish as a credentials failure.
# NOTE: keep comments OUT of the backslash-continued block below. A `#` line inside it is a SHELL
# comment that swallows the rest of the joined line, so the http-code test then ran in a new shell
# with an empty `code` and failed EVERY publish, 202 included.
	@code=`printf 'header = "Authorization: Bearer %s:%s"\n' "$${PACKAGIST_USERNAME}" "$${PACKAGIST_API_TOKEN}" \
		| curl --silent --show-error --location --config - \
		--output build/packagist-update-response.json --write-out '%{http_code}' \
		-X POST -H 'Content-Type: application/json' \
		-d '$(PACKAGIST_UPDATE_PAYLOAD)' \
		"${PACKAGIST_UPDATE_API}"`; \
	echo "$(BLUE)[INFO]$(NC) Packagist answered HTTP $$code"; \
	cat build/packagist-update-response.json; echo; \
	if [ "$$code" != "200" ] && [ "$$code" != "202" ]; then \
		echo "$(RED)[ERROR]$(NC) Packagist rejected the update (HTTP $$code). 40x means the credentials are wrong or ${PACKAGIST_PACKAGE} has never been submitted - see README 'Publishing to Packagist'"; \
		exit 1; \
	fi; \
	grep -q '"status" *: *"success"' build/packagist-update-response.json \
		|| { echo "$(RED)[ERROR]$(NC) Packagist returned HTTP $$code without status=success - see the response above"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist is crawling ${PACKAGIST_REPOSITORY_URL}"

########################################################
#		DEVOPS-ACCOUNTS

# One shell, in this order: spc (anchored, read-only) -> read the three credentials -> DELETE the
# clone -> $(SUBMAKE) release. The clone lives in a private `mktemp -d` directory OUTSIDE the
# repository (it used to be cloned into the repository root, which the compiler image then mounted
# as a root container, and it was only removed when the release SUCCEEDED). It is deleted before the
# release starts, and the EXIT/INT/TERM trap deletes it on every other way out of this shell too.
# With DEVOPS_ACCOUNT_DIR set (ondewo-sip-api's release driver passes its single clone) nothing is
# cloned and nothing is deleted.
#
# The credentials reach the sub-make through its ENVIRONMENT: `make release NAME=<value>` would put
# every value on make's argv, which /proc/<pid>/cmdline shows to every user on the host. The greps
# are anchored (^NAME=) so a comment line that mentions a variable name cannot corrupt its value.
ondewo_release: spc ## Release with credentials from devops-accounts (cloned to a temp dir and deleted before the release starts)
	@set -e; \
	tmp=""; \
	cleanup() { if [ -n "$$tmp" ]; then rm -rf "$$tmp"; tmp=""; echo "$(BLUE)[INFO]$(NC) deleted the devops-accounts clone"; fi; }; \
	trap cleanup EXIT; trap 'cleanup; exit 130' INT; trap 'cleanup; exit 143' TERM; \
	if [ -n "${DEVOPS_ACCOUNT_DIR}" ]; then \
		[ -d "${DEVOPS_ACCOUNT_DIR}" ] || { echo "$(RED)[ERROR]$(NC) DEVOPS_ACCOUNT_DIR='${DEVOPS_ACCOUNT_DIR}' is not a directory"; exit 1; }; \
		accounts="${DEVOPS_ACCOUNT_DIR}"; \
		echo "$(BLUE)[INFO]$(NC) using the devops-accounts clone at DEVOPS_ACCOUNT_DIR (not cloned, not deleted here)"; \
	else \
		tmp=`mktemp -d "$${TMPDIR:-/tmp}/${DEVOPS_ACCOUNT_GIT}.XXXXXX"`; \
		git clone --quiet --depth 1 ${DEVOPS_ACCOUNT_REPO} "$$tmp/${DEVOPS_ACCOUNT_GIT}"; \
		accounts="$$tmp/${DEVOPS_ACCOUNT_GIT}"; \
	fi; \
	set -a; \
	eval "$$(grep -h -E '^GITHUB_GH_TOKEN=' "$$accounts/account_github.env"; \
		grep -h -E '^(PACKAGIST_USERNAME|PACKAGIST_API_TOKEN)=' "$$accounts/account_packagist.env")"; \
	set +a; \
	cleanup; \
	$(SUBMAKE) release

# All three tests are ANCHORED, and none of them can pass by accident:
#   * Test 1/2: the release branch and the tag, locally (`git rev-parse -q --verify` of the exact
#     ref) AND on origin (`git ls-remote --exit-code` of the exact ref: exit 2 = absent, anything
#     else but 0 = origin unreachable, which fails rather than passing). The old substring greps
#     matched release/7.1.0-rc1 and 17.1.0, and only ever looked at the LOCAL refs.
#   * Test 3: Packagist does not already serve the version. It replaces a composer.json check that
#     could not fail: `ondewo_release` ran `update_composer_version` right before it, so the field
#     always held the version it compared against.
spc: ## Read-only: fail if release/<V> or tag <V> exists (locally or on origin) or Packagist already serves <V>
	@if git rev-parse -q --verify "refs/heads/release/${ONDEWO_SIP_VERSION}" > /dev/null; then \
		echo "-- Test 1: local branch release/${ONDEWO_SIP_VERSION} exists!!"; exit 1; fi; \
	rc=0; git ls-remote --exit-code --heads origin "refs/heads/release/${ONDEWO_SIP_VERSION}" > /dev/null || rc=$$?; \
	case $$rc in \
		0) echo "-- Test 1: Branch release/${ONDEWO_SIP_VERSION} exists on origin!!"; exit 1;; \
		2) echo "-- Test 1: Branch is fine";; \
		*) echo "-- Test 1: git ls-remote origin failed (exit $$rc)!!"; exit 1;; \
	esac
	@if git rev-parse -q --verify "refs/tags/${ONDEWO_SIP_VERSION}" > /dev/null; then \
		echo "-- Test 2: local tag ${ONDEWO_SIP_VERSION} exists!!"; exit 1; fi; \
	rc=0; git ls-remote --exit-code --tags origin "refs/tags/${ONDEWO_SIP_VERSION}" > /dev/null || rc=$$?; \
	case $$rc in \
		0) echo "-- Test 2: Tag ${ONDEWO_SIP_VERSION} exists on origin!!"; exit 1;; \
		2) echo "-- Test 2: Tag is fine";; \
		*) echo "-- Test 2: git ls-remote origin failed (exit $$rc)!!"; exit 1;; \
	esac
	@mkdir -p build
	@code=`curl --silent --show-error --output build/packagist-p2.json --write-out '%{http_code}' "${PACKAGIST_P2_URL}"` \
		|| { echo "-- Test 3: could not reach ${PACKAGIST_P2_URL}!!"; exit 1; }; \
	case $$code in \
		404) echo "-- Test 3: ${PACKAGIST_PACKAGE} is not on Packagist yet - version is fine";; \
		200) rc=0; V='${ONDEWO_SIP_VERSION}' P='${PACKAGIST_PACKAGE}' ${PACKAGIST_LISTS_VERSION} build/packagist-p2.json || rc=$$?; \
			case $$rc in \
				0) echo "-- Test 3: Packagist already serves ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION}!!"; exit 1;; \
				1) echo "-- Test 3: Packagist does not serve ${ONDEWO_SIP_VERSION} - version is fine";; \
				*) echo "-- Test 3: ${PACKAGIST_P2_URL} is not valid JSON!!"; exit 1;; \
			esac;; \
		*) echo "-- Test 3: ${PACKAGIST_P2_URL} answered HTTP $$code!!"; exit 1;; \
	esac
