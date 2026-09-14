export

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
# Overriding variables: pass on the command line, e.g. `make build PROTO_COMPILER_IMAGE=...`,
# or export in the environment. Credentials (GITHUB_GH_TOKEN) are only ever read at runtime
# and must never be committed.
# =====================================================================================

# ---------------- BEFORE RELEASE ----------------
# 1 - Update Version Number (ONDEWO_SIP_VERSION below)
# 2 - Update RELEASE.md
# 3 - make build
# -------------- Release Process Steps --------------
# 1 - Get Credentials from devops-accounts repo
# 2 - Create Release Branch and push
# 3 - Create Release Tag and push
# 4 - GitHub Release
# 5 - Packagist Release (`make publish`: there is no upload step - Packagist serves the git tag,
#     so the release only validates the package and pings the update API to have it crawled)

########################################################
# 		Variables
########################################################

# MUST BE THE SAME AS THE API in Major and Minor Version Number
# example: API 2.9.0 --> Client 2.9.X
ONDEWO_SIP_VERSION=5.4.0

# Submodule pins. Both are checked out by `make checkout_defined_submodule_versions`, so the
# stubs of a release are always reproducible from the two commits recorded here.
ONDEWO_SIP_API_GIT_BRANCH=tags/5.4.0
ONDEWO_PROTO_COMPILER_GIT_BRANCH=tags/5.15.0

# Submodule directories - both sit at the repository root, see .gitmodules
ONDEWO_SIP_API_DIR=ondewo-sip-api
ONDEWO_PROTO_COMPILER_DIR=ondewo-proto-compiler

# The FIXED image tag is the only contract with the proto compiler: `make build_compiler`
# rebuilds this very tag from the submodule, and `make generate_ondewo_protos` runs it.
PROTO_COMPILER_IMAGE=ondewo-php-proto-compiler:latest

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

# You need to setup an access token at https://github.com/settings/tokens - permissions are important
GITHUB_GH_TOKEN?=ENTER_YOUR_TOKEN_HERE

# Terminate on the ***** separator that delimits release entries, NOT on /\*\*/ - that matched the
# first markdown **bold** span inside the entry and silently truncated the notes there, with no
# error from `gh release create`. Same fix as ondewo-nlu-api's and ondewo-nlu-client-python's Makefile.
CURRENT_RELEASE_NOTES=`cat RELEASE.md \
	| perl -ne 'print if /Release ONDEWO SIP PHP Client ${ONDEWO_SIP_VERSION}/../^\*{5}/'`

GH_REPO="https://github.com/ondewo/ondewo-sip-client-php"
DEVOPS_ACCOUNT_GIT="ondewo-devops-accounts"
DEVOPS_ACCOUNT_DIR="./${DEVOPS_ACCOUNT_GIT}"

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
# GH_REPO carries its double quotes as part of the VALUE (harmless in a recipe, where the shell
# strips them again); the JSON payload below is assembled by make itself, so they come off here.
PACKAGIST_REPOSITORY_URL=$(subst ",,$(GH_REPO))
# The request body of the update API. It carries no credentials - those travel as query
# parameters - which is why `make packagist_dry_run` can verify the exact payload the real
# publish sends without holding a single secret.
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

precommit_hooks_run_all_files: ## Runs all pre-commit hooks on all files and not just the changed ones
	pre-commit run --all-files

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

build: checkout_defined_submodule_versions build_compiler generate_ondewo_protos fix_generated_ownership update_composer_version ## Build the client: submodules -> compiler image -> stubs -> version bump
	@echo "$(GREEN)[SUCCESS]$(NC) ondewo-sip-client-php ${ONDEWO_SIP_VERSION} built"

build_compiler: ## Build the ondewo-php-proto-compiler docker image from the submodule
	@echo "$(BLUE)[INFO]$(NC) Building ${PROTO_COMPILER_IMAGE} from ${ONDEWO_PROTO_COMPILER_DIR}/php ..."
	cd ${ONDEWO_PROTO_COMPILER_DIR}/php && sh build.sh
	@echo "$(GREEN)[SUCCESS]$(NC) ${PROTO_COMPILER_IMAGE} built"

# NOTE: no `-it`. It breaks every non-interactive caller (CI, `make release`) with
#	"cannot attach stdin to a TTY-enabled container because stdin is not a terminal".
#	-it belongs only on the `--entrypoint /bin/bash` debug command documented in the README.
# NOTE: input volume AND output volume are both the repository root. The image copies the input
#	volume into an internal temp directory and compiles there, so the mounted input is never
#	mutated; it then writes composer.json, composer.lock, src/ and vendor/ back here, wiping its
#	own src/ and vendor/ first so a renamed or deleted proto leaves no orphaned stub behind.
#	The repository root has to be the input volume because the image reads BOTH the proto root
#	(${ONDEWO_SIP_API_DIR}) and this package's composer.json from there - it merges
#	that manifest with its own defaults instead of overwriting it.
# NOTE: hand-written PHP belongs in auth/ at the repository root, NEVER in src/. src/ is
#	compiler-owned and wiped on every run; the image adds auth/ to the shipped classmap itself.
generate_ondewo_protos: ## Generate the PHP gRPC client stubs from the .proto definitions into src/
	@test -d ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} \
		|| { echo "$(RED)[ERROR]$(NC) '${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR}' not found - run 'make update_submodules' first"; exit 1; }
	@echo "$(BLUE)[INFO]$(NC) Generating PHP stubs from ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} ..."
	docker run --rm \
		-v ${shell pwd}:/input-volume \
		-v ${shell pwd}:/output-volume \
		${PROTO_COMPILER_IMAGE} ${ONDEWO_SIP_API_DIR} ${PROTOS_TARGET_SUBDIR}
	@echo "$(GREEN)[SUCCESS]$(NC) PHP stubs generated in src/"

# The compiler image writes under /image-data and therefore runs as root (unlike the python
# target, which passes --user), so everything it copies out is root-owned. Non-fatal: a rootless
# or userns-remapped docker daemon already produces user-owned output and needs no sudo at all.
fix_generated_ownership: ## Give the generated files back to the current user (they are written by a root container)
	@echo "$(YELLOW)[WARN]$(NC) The generated files are root-owned - you may be prompted for sudo"
	@find . -maxdepth 1 -group root | while IFS= read -r f; do \
		sudo chown -R `id -un`:`id -gn` "$$f" && echo "$(BLUE)[INFO]$(NC) chowned $$f"; \
	done || true

update_composer_version: ## Set ONDEWO_SIP_VERSION as the `version` field of composer.json
	@perl -i -pe 's/^(\s*"version":\s*")[0-9]+\.[0-9]+\.[0-9]+(")/$${1}${ONDEWO_SIP_VERSION}$${2}/' composer.json
	@echo "$(GREEN)[SUCCESS]$(NC) composer.json version set to ${ONDEWO_SIP_VERSION}"

install_dependencies: ## Resolve and install the composer dependencies of the package (needs network)
# composer.json declares autoload.classmap ["src/"], and `composer update` aborts with
#	'Could not scan for classes inside "src/" which does not appear to be a file nor a folder'
#	when the stubs have not been generated yet. An empty src/ is harmless - it is compiler-owned
#	and wiped on the next generation run, and git does not track empty directories.
	@mkdir -p src
	composer update --prefer-dist --no-interaction --no-progress

install_dev_tools: ## Resolve and install PHPUnit + the coverage gate into tools/vendor (needs network)
# No lock file is committed for tools/: it would pin ONE phpunit major, and this repository is
#	tested on php 8.1 (phpunit 10) through 8.4 (phpunit 11) - the constraint has to be re-resolved
#	per interpreter, which is what `update` does and `install` cannot.
	composer update --working-dir=${TOOLS_DIR} --prefer-dist --no-interaction --no-progress

clean: ## Remove the composer artifacts, the dev tools and the generated stubs
	rm -rf vendor composer.lock src ${TOOLS_DIR}/vendor ${TOOLS_DIR}/composer.lock build .phpunit.cache

########################################################
#		Test

test: composer_validate packagist_dry_run lint_php check_build coverage ## Validate the manifest, verify the packaging path, syntax-check hand-written PHP, check the stubs against the api submodule and run the covered PHPUnit suite

# What .github/workflows/ci.yml runs, verbatim. It deliberately leaves `check_build` out: that
# target compares src/ against the ondewo-sip-api submodule, and CI checks out NO submodules -
# the stubs are committed, so what CI has to prove is that the COMMITTED tree builds and passes
# its tests. tests/Generated/GeneratedCodeTest.php carries the submodule-free half of the same
# assertion (every generated class loads, every descriptor initialises, every expected service
# client exists) and fails - never skips - when src/ is missing.
ci: composer_validate packagist_dry_run lint_php coverage ## Run the CI gate locally (no submodules, no docker)
	@echo "$(GREEN)[SUCCESS]$(NC) CI gate passed"

composer_validate: ## Validate composer.json
# Deliberately NOT --strict: the `version` field the release targets bump is a strict-mode
# warning that --strict turns into a failure (rc 1), exactly as in the compiler image.
# `composer_validate_strict` below runs --strict anyway, with the deliberate warnings enumerated,
# so a NEW warning still fails the build.
	composer validate --no-check-publish --no-interaction

lint_php: ## Syntax-check every hand-written PHP file with `php -l` (src/ is generated and skipped)
	@for dir in auth tests examples; do \
		[ -d "$$dir" ] || continue; \
		find "$$dir" -type f -name '*.php' | while IFS= read -r f; do \
			php -l "$$f" > /dev/null || { echo "$(RED)[ERROR]$(NC) php -l failed: $$f"; exit 1; }; \
		done || exit 1; \
	done
	@echo "$(GREEN)[SUCCESS]$(NC) hand-written PHP sources are syntactically valid"

check_build: ## Fails if any .proto of the API submodule has no generated PHP code
	@test -d src \
		|| { echo "$(RED)[ERROR]$(NC) src/ is missing - run 'make generate_ondewo_protos' first"; exit 1; }
	@test -d ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} \
		|| { echo "$(RED)[ERROR]$(NC) '${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR}' not found - run 'make update_submodules' first"; exit 1; }
# protoc's php generator names a file after the UpperCamel form of the .proto basename
# (ai_services.proto -> AiServices.php), so the basename is camel-cased before it is looked up.
	@find ${ONDEWO_SIP_API_DIR}/${PROTOS_TARGET_SUBDIR} -type f -name '*.proto' \
		| while IFS= read -r proto; do \
			camel=`basename "$$proto" .proto | awk -F'_' '{s=""; for(i=1;i<=NF;i++){s = s toupper(substr($$i,1,1)) substr($$i,2)}; print s}'`; \
			find src -type f -name "$$camel.php" | grep -q . \
				|| { echo "$(RED)[ERROR]$(NC) No PHP code generated for $$proto (expected a $$camel.php)"; exit 1; }; \
		done || exit 1
	@echo "$(GREEN)[SUCCESS]$(NC) every .proto has generated PHP code"

# NOTE: no `[ -x ... ] || skip` guard. A missing tool chain or a missing test suite is a RED
#	build, not a green one - that guard is exactly what let this repository's CI pass while it
#	held no code at all.
phpunit: ## Run the PHPUnit suite
	@test -x ${PHPUNIT} \
		|| { echo "$(RED)[ERROR]$(NC) '${PHPUNIT}' is missing - run 'make install_dev_tools'"; exit 1; }
	@test -f vendor/autoload.php \
		|| { echo "$(RED)[ERROR]$(NC) 'vendor/autoload.php' is missing - run 'make install_dependencies'"; exit 1; }
	${PHPUNIT} --colors=never

coverage: ## Run the PHPUnit suite with coverage and fail below COVERAGE_MIN% of the hand-written code
	@test -x ${PHPUNIT} \
		|| { echo "$(RED)[ERROR]$(NC) '${PHPUNIT}' is missing - run 'make install_dev_tools'"; exit 1; }
	@test -f vendor/autoload.php \
		|| { echo "$(RED)[ERROR]$(NC) 'vendor/autoload.php' is missing - run 'make install_dependencies'"; exit 1; }
# --fail-on-skipped/--fail-on-incomplete: a test that quietly skips itself is the green-by-omission
#	failure mode this suite exists to rule out.
	php ${PHP_COVERAGE_FLAGS} ${PHPUNIT} --colors=never --fail-on-skipped --fail-on-incomplete \
		--coverage-clover ${CLOVER_REPORT} --coverage-text
	@test -f ${CLOVER_REPORT} \
		|| { echo "$(RED)[ERROR]$(NC) no coverage report at '${CLOVER_REPORT}' - is a coverage driver (pcov/xdebug) enabled?"; exit 1; }
	${COVERAGE_CHECK} ${CLOVER_REPORT} ${COVERAGE_MIN}

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

release: ## Automate the entire release process
	@echo "$(BLUE)[INFO]$(NC) Start Release"
	make build
	-make precommit_hooks_run_all_files
	make check_build
	git status
	git add src
	git add composer.json
	git add Makefile
	git add README.md
	git add RELEASE.md
# auth/ is the hand-written surface (bearer credentials, Keycloak token provider). It is
# top-level and NOT covered by `git add src`, so leaving it out means a fix written there is
# published from the tag without ever reaching the repository.
	-git add auth
# tests/, tools/ and examples/ are not part of the published classmap, but a regression test
# written alongside a fix must reach the repository or CI never runs it.
	-git add tests examples tools phpunit.xml.dist
	git add ${ONDEWO_PROTO_COMPILER_DIR}
	git add ${ONDEWO_SIP_API_DIR}
	git status
	-git commit --no-verify -m "PREPARING FOR RELEASE ${ONDEWO_SIP_VERSION}"
	git push
	make create_release_branch
	make create_release_tag
	make push_to_gh
# The PHP equivalent of `make push_to_pypi_via_docker` / `make publish_npm_via_docker`: nothing is
# uploaded, the tag pushed above IS the artifact, and this only tells Packagist to crawl it. It
# has to run AFTER create_release_tag - Packagist crawls what is on GitHub at that moment.
	make publish
	@echo "$(GREEN)[SUCCESS]$(NC) Release Finished - ${PACKAGIST_PACKAGE} ${ONDEWO_SIP_VERSION} is on Packagist"

create_release_branch: ## Create Release Branch and push it to origin
	git checkout -b "release/${ONDEWO_SIP_VERSION}"
	git push -u origin "release/${ONDEWO_SIP_VERSION}"

create_release_tag: ## Create Release Tag and push it to origin
	git tag -a ${ONDEWO_SIP_VERSION} -m "release/${ONDEWO_SIP_VERSION}"
	git push origin ${ONDEWO_SIP_VERSION}

########################################################
#		GITHUB

push_to_gh: login_to_gh build_gh_release ## Logs into GitHub CLI and Releases
	@echo "$(GREEN)[SUCCESS]$(NC) Released to GitHub"

# Prefixed with @ so the token never reaches the build log.
login_to_gh: ## Login to Github CLI with Access Token
	@test "${GITHUB_GH_TOKEN}" != "ENTER_YOUR_TOKEN_HERE" \
		|| { echo "$(RED)[ERROR]$(NC) GITHUB_GH_TOKEN is not set - create one at https://github.com/settings/tokens"; exit 1; }
	@echo $(GITHUB_GH_TOKEN) | gh auth login -p ssh --with-token

build_gh_release: ## Generate Github Release with CLI
	gh release create --repo $(GH_REPO) "$(ONDEWO_SIP_VERSION)" -n "$(CURRENT_RELEASE_NOTES)" -t "Release ${ONDEWO_SIP_VERSION}"

########################################################
#		PACKAGIST

# The equivalent of `twine upload` (python) or `npm publish` (typescript) - except that Packagist
# accepts no artifact at all. It reads the git tag straight off GitHub, so the only thing left to
# do is (1) prove the tagged tree is a publishable composer package and (2) ask Packagist to crawl
# it now instead of at its next scheduled pass.
# check_packagist_credentials runs FIRST so a missing token fails in a second rather than after
# the whole validation pass.
publish: check_packagist_credentials packagist_dry_run packagist_update ## Validate the package and tell Packagist to crawl the new tag (the PHP equivalent of an upload)
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
composer_validate_strict: ## Run `composer validate --strict` and fail on any warning beyond the three deliberate ones
	@mkdir -p build
	@composer validate --strict --no-check-lock --no-ansi --no-interaction > build/composer-validate-strict.log 2>&1 || true
	@cat build/composer-validate-strict.log
	@grep -q "is valid" build/composer-validate-strict.log \
		|| { echo "$(RED)[ERROR]$(NC) composer.json is INVALID - see the output above"; exit 1; }
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
check_version_agreement: ## Fail unless ONDEWO_SIP_VERSION, composer.json, RELEASE.md and (when HEAD is a tag) the git tag all agree
	@name=`php -r 'echo json_decode(file_get_contents("composer.json"), true)["name"] ?? "";'`; \
	version=`php -r 'echo json_decode(file_get_contents("composer.json"), true)["version"] ?? "";'`; \
	if [ "$$name" != "${PACKAGIST_PACKAGE}" ]; then \
		echo "$(RED)[ERROR]$(NC) composer.json name is '$$name' but the Packagist package is '${PACKAGIST_PACKAGE}'"; exit 1; fi; \
	if [ "$$version" != "${ONDEWO_SIP_VERSION}" ]; then \
		echo "$(RED)[ERROR]$(NC) composer.json version is '$$version' but ONDEWO_SIP_VERSION is '${ONDEWO_SIP_VERSION}' - run 'make update_composer_version'"; exit 1; fi; \
	grep -qF "Release ONDEWO SIP PHP Client ${ONDEWO_SIP_VERSION}" RELEASE.md \
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
check_packagist_payload: ## Fail unless the Packagist update payload is well-formed JSON pointing at this repository
	@mkdir -p build
	@printf '%s\n' '$(PACKAGIST_UPDATE_PAYLOAD)' > build/packagist-update-payload.json
	@url=`php -r 'echo json_decode(file_get_contents("build/packagist-update-payload.json"), true)["repository"]["url"] ?? "";'`; \
	source=`php -r 'echo json_decode(file_get_contents("composer.json"), true)["support"]["source"] ?? "";'`; \
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

# Prefixed with @ so neither credential reaches the build log, and written against the EXPORTED
# shell variables (this Makefile exports everything, see line 1) rather than against
# $(PACKAGIST_API_TOKEN): should the @ ever be dropped, make then echoes the variable NAME instead
# of the token. --fail is deliberately NOT used - the http code is inspected by hand so a 403 is
# reported as "bad credentials" instead of curl's bare exit 22.
packagist_update: ## Ping the Packagist update API so it crawls the tags of this repository
	@mkdir -p build
	@echo "$(BLUE)[INFO]$(NC) Asking Packagist to crawl ${PACKAGIST_REPOSITORY_URL} ..."
	@code=`curl --silent --show-error --location \
		--output build/packagist-update-response.json --write-out '%{http_code}' \
		-X POST -H 'Content-Type: application/json' \
		-d '$(PACKAGIST_UPDATE_PAYLOAD)' \
		"${PACKAGIST_UPDATE_API}?username=$${PACKAGIST_USERNAME}&apiToken=$${PACKAGIST_API_TOKEN}"`; \
	echo "$(BLUE)[INFO]$(NC) Packagist answered HTTP $$code"; \
	cat build/packagist-update-response.json; echo; \
	if [ "$$code" != "200" ]; then \
		echo "$(RED)[ERROR]$(NC) Packagist rejected the update (HTTP $$code). 40x means the credentials are wrong or ${PACKAGIST_PACKAGE} has never been submitted - see README 'Publishing to Packagist'"; \
		exit 1; \
	fi
	@grep -q '"status" *: *"success"' build/packagist-update-response.json \
		|| { echo "$(RED)[ERROR]$(NC) Packagist returned HTTP 200 without status=success - see the response above"; exit 1; }
	@echo "$(GREEN)[SUCCESS]$(NC) Packagist is crawling ${PACKAGIST_REPOSITORY_URL}"

########################################################
#		DEVOPS-ACCOUNTS

ondewo_release: spc clone_devops_accounts run_release_with_devops ## Release with credentials from devops-accounts repo
	@rm -rf ${DEVOPS_ACCOUNT_GIT}

clone_devops_accounts: ## Clones devops-accounts repo
	if [ -d $(DEVOPS_ACCOUNT_GIT) ]; then rm -Rf $(DEVOPS_ACCOUNT_GIT); fi
	git clone git@bitbucket.org:ondewo/${DEVOPS_ACCOUNT_GIT}.git

run_release_with_devops: ## Gets Credentials from devops-repo and run release command with them
	$(eval info:= $(shell cat ${DEVOPS_ACCOUNT_DIR}/account_github.env | grep GITHUB_GH & cat ${DEVOPS_ACCOUNT_DIR}/account_packagist.env | grep PACKAGIST_USERNAME & cat ${DEVOPS_ACCOUNT_DIR}/account_packagist.env | grep PACKAGIST_API_TOKEN))
	@make release $(info)

spc: ## Checks if the Release Branch, Tag and composer.json version already exist
	$(eval filtered_branches:= $(shell git branch --all | grep "release/${ONDEWO_SIP_VERSION}"))
	$(eval filtered_tags:= $(shell git tag --list | grep "${ONDEWO_SIP_VERSION}"))
	$(eval composer_version:= $(shell grep '"version"' composer.json))
	@if test "$(filtered_branches)" != ""; then echo "-- Test 1: Branch exists!!" & exit 1; else echo "-- Test 1: Branch is fine";fi
	@if test "$(filtered_tags)" != ""; then echo "-- Test 2: Tag exists!!" & exit 1; else echo "-- Test 2: Tag is fine";fi
	@if test "$(composer_version)" = ""; then echo "-- Test 3: composer.json has no version field!!" & exit 1; else echo "-- Test 3: composer.json is fine";fi
