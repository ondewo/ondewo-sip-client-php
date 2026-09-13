export

# =====================================================================================
# ondewo-sip-client-php - Makefile
#
# The ONDEWO SIP (SIP Telephony) gRPC client for PHP. There is no hand-written
# transport layer here: the whole client surface is generated from the .proto definitions of
# ondewo-sip-api by the ondewo-proto-compiler's `ondewo-php-proto-compiler` image, and the
# repository root IS the composer package that gets published.
#
# Quick start:
#   make help                    # list every documented target
#   make makefile_chapters       # list the section headers below
#   make update_submodules       # fetch ondewo-sip-api + ondewo-proto-compiler
#   make build                   # submodules -> compiler image -> stubs -> version bump
#   make test                    # manifest validation + php -l + PHPUnit (when present)
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
# 5 - Packagist Release (the GitHub webhook pulls the new tag - there is no upload step)

########################################################
# 		Variables
########################################################

# MUST BE THE SAME AS THE API in Major and Minor Version Number
# example: API 2.9.0 --> Client 2.9.X
ONDEWO_SIP_VERSION=5.4.0

# Submodule pins. Both are checked out by `make checkout_defined_submodule_versions`, so the
# stubs of a release are always reproducible from the two commits recorded here.
ONDEWO_SIP_API_GIT_BRANCH=tags/5.4.0
# PROVISIONAL PIN. The six compiled-language targets this client depends on landed on the
# branch below; ondewo-proto-compiler 5.15.0 is not tagged yet. Once that release is cut this
# becomes tags/5.15.0 - `make release_update_proto_compiler_dependency_<lang>` in the compiler
# repo rewrites it, so do not hand-edit it then.
ONDEWO_PROTO_COMPILER_GIT_BRANCH=feature/rust-php-go-cpp-java-csharp

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

setup_developer_environment_locally: update_submodules install_dependencies install_precommit_hooks ## Ready a fresh laptop: submodules, composer dependencies and pre-commit hooks

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

install_dependencies: ## Resolve and install the composer dependencies (needs network, includes dev)
# composer.json declares autoload.classmap ["src/"], and `composer update` aborts with
#	'Could not scan for classes inside "src/" which does not appear to be a file nor a folder'
#	when the stubs have not been generated yet. An empty src/ is harmless - it is compiler-owned
#	and wiped on the next generation run, and git does not track empty directories.
	@mkdir -p src
	composer update --prefer-dist --no-interaction --no-progress

clean: ## Remove the composer artifacts and the generated stubs
	rm -rf vendor composer.lock src

########################################################
#		Test

test: composer_validate lint_php check_build phpunit ## Validate the manifest, syntax-check hand-written PHP, check the stubs and run PHPUnit

composer_validate: ## Validate composer.json
# Deliberately NOT --strict: the `version` field the release targets bump is a strict-mode
# warning that --strict turns into a failure (rc 2), exactly as in the compiler image.
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

phpunit: ## Run the PHPUnit suite when one is present
	@if [ -x vendor/bin/phpunit ] && [ -d tests ]; then \
		vendor/bin/phpunit --colors=never; \
	else \
		echo "$(YELLOW)[WARN]$(NC) no vendor/bin/phpunit or no tests/ - skipping (run 'make install_dependencies')"; \
	fi

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
# tests/ and examples/ are not part of the published classmap, but a regression test written
# alongside a fix must reach the repository or CI never runs it.
	-git add tests examples
	git add ${ONDEWO_PROTO_COMPILER_DIR}
	git add ${ONDEWO_SIP_API_DIR}
	git status
	-git commit --no-verify -m "PREPARING FOR RELEASE ${ONDEWO_SIP_VERSION}"
	git push
	make create_release_branch
	make create_release_tag
	make push_to_gh
	@echo "$(GREEN)[SUCCESS]$(NC) Release Finished - Packagist picks the new tag up from the GitHub webhook"

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
#		DEVOPS-ACCOUNTS

ondewo_release: spc clone_devops_accounts run_release_with_devops ## Release with credentials from devops-accounts repo
	@rm -rf ${DEVOPS_ACCOUNT_GIT}

clone_devops_accounts: ## Clones devops-accounts repo
	if [ -d $(DEVOPS_ACCOUNT_GIT) ]; then rm -Rf $(DEVOPS_ACCOUNT_GIT); fi
	git clone git@bitbucket.org:ondewo/${DEVOPS_ACCOUNT_GIT}.git

run_release_with_devops: ## Gets Credentials from devops-repo and run release command with them
	$(eval info:= $(shell cat ${DEVOPS_ACCOUNT_DIR}/account_github.env | grep GITHUB_GH))
	@make release $(info)

spc: ## Checks if the Release Branch, Tag and composer.json version already exist
	$(eval filtered_branches:= $(shell git branch --all | grep "release/${ONDEWO_SIP_VERSION}"))
	$(eval filtered_tags:= $(shell git tag --list | grep "${ONDEWO_SIP_VERSION}"))
	$(eval composer_version:= $(shell grep '"version"' composer.json))
	@if test "$(filtered_branches)" != ""; then echo "-- Test 1: Branch exists!!" & exit 1; else echo "-- Test 1: Branch is fine";fi
	@if test "$(filtered_tags)" != ""; then echo "-- Test 2: Tag exists!!" & exit 1; else echo "-- Test 2: Tag is fine";fi
	@if test "$(composer_version)" = ""; then echo "-- Test 3: composer.json has no version field!!" & exit 1; else echo "-- Test 3: composer.json is fine";fi
