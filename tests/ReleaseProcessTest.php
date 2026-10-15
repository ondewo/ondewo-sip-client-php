<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The release contract every ondewo-sip-client-<language> shares, pinned on this Makefile:
 *
 *   ondewo_release: spc -> load the credentials -> DELETE the devops-accounts clone -> make release
 *   release:        release_preflight -> build -> tests -> local commit -> push / branch / tag
 *                   (Packagist serves the TAG, so these precede the publish step) -> publish ->
 *                   GitHub release -> release_verify
 *
 * Each test parses the recipes rather than running them: a release cannot be rehearsed without
 * pushing, and the order is exactly what a well-meant edit silently breaks.
 */
final class ReleaseProcessTest extends TestCase
{
    /**
     * The steps of `release`, in the order they must run, each as a pattern for its recipe line.
     */
    private const RELEASE_ORDER = [
        'preflight' => '/^\$\(MAKE\) release_preflight$/',
        'build' => '/^\$\(WITHOUT_CREDENTIALS\) \$\(MAKE\) build$/',
        'pre-commit' => '/^\$\(WITHOUT_CREDENTIALS\) \$\(MAKE\) precommit_hooks_run_all_files$/',
        'tests' => '/^\$\(WITHOUT_CREDENTIALS\) \$\(MAKE\) install_dependencies install_dev_tools test$/',
        'commit' => '/^git diff --cached --quiet \|\| git commit --no-verify /',
        'push' => '/^\$\(WITHOUT_CREDENTIALS\) git push$/',
        'release branch' => '/^\$\(WITHOUT_CREDENTIALS\) \$\(MAKE\) create_release_branch$/',
        'tag' => '/^\$\(WITHOUT_CREDENTIALS\) \$\(MAKE\) create_release_tag$/',
        'publish' => '/^\$\(MAKE\) publish$/',
        'GitHub release' => '/^\$\(MAKE\) push_to_gh$/',
        'verify' => '/^\$\(MAKE\) release_verify$/',
    ];

    public function testTheReleaseRunsItsStepsInTheContractOrder(): void
    {
        $commands = self::commands(self::recipe('release'));
        $positions = [];
        foreach (self::RELEASE_ORDER as $step => $pattern) {
            $matches = array_keys(preg_grep($pattern, $commands));
            self::assertCount(1, $matches, 'the release recipe must run the "' . $step . '" step exactly once');
            $positions[$step] = $matches[0];
        }

        $sorted = $positions;
        asort($sorted);
        self::assertSame(array_keys(self::RELEASE_ORDER), array_keys($sorted), 'the release steps are out of order');
    }

    public function testNothingIsCommittedBeforeTheWholeSuiteRan(): void
    {
        $commands = self::commands(self::recipe('release'));
        $tests = array_key_first(preg_grep(self::RELEASE_ORDER['tests'], $commands));
        $firstGitWrite = array_key_first(preg_grep('/\bgit (?:add|commit|push|tag|checkout)\b/', $commands));

        self::assertNotNull($tests);
        self::assertNotNull($firstGitWrite);
        self::assertLessThan($firstGitWrite, $tests);
        // `test` is the FULL suite, including the rpc drift check against the api submodule.
        self::assertMatchesRegularExpression('/^test: .*\bcheck_build\b.*\bcoverage\b/m', self::read('Makefile'));
    }

    public function testNoReleaseStepIgnoresItsOwnFailure(): void
    {
        foreach (['release', 'ondewo_release', 'release_preflight', 'release_preflight_checks', 'release_verify', 'release_verify_checks', 'publish', 'spc'] as $target) {
            self::assertSame([], preg_grep('/^-/', self::commands(self::recipe($target))), $target . ' swallows a failing step with a leading `-`');
        }
    }

    public function testThePreflightIsReadOnlyAndChecksWhatTheContractNames(): void
    {
        $prerequisites = self::prerequisites('release_preflight_checks');
        foreach (['check_host_tools', 'spc', 'check_release_notes', 'check_gh_push_permission', 'check_packagist_auth'] as $check) {
            self::assertContains($check, $prerequisites, 'release_preflight does not run ' . $check);
        }

        $writes = '/\bgit (?:add|commit|push|tag|checkout)\b|\brelease create\b|' . preg_quote('$(PACKAGIST_UPDATE_PAYLOAD)', '/') . '/';
        foreach (array_merge(['release_preflight', 'release_preflight_checks', 'release_verify', 'release_verify_checks'], $prerequisites) as $target) {
            if (!str_contains(self::read('Makefile'), "\n" . $target . ':')) {
                continue;
            }
            self::assertSame([], preg_grep($writes, self::commands(self::recipe($target))), $target . ' is not read-only');
        }
    }

    public function testTheGithubCredentialIsProvedValidNotJustPresent(): void
    {
        self::assertMatchesRegularExpression(
            '/GH_TOKEN="\$\$\{GITHUB_GH_TOKEN\}" \$\{GH_CMD\} api repos\/\$\{GH_REPO_SLUG\} --jq \.permissions\.push/',
            self::recipe('check_gh_push_permission'),
        );
    }

    public function testSpcChecksOriginAndPackagistWithAnchoredRefs(): void
    {
        $spc = self::recipe('spc');

        self::assertStringContainsString('git ls-remote --exit-code --heads origin "refs/heads/release/${ONDEWO_SIP_VERSION}"', $spc);
        self::assertStringContainsString('git ls-remote --exit-code --tags origin "refs/tags/${ONDEWO_SIP_VERSION}"', $spc);
        self::assertStringContainsString('git rev-parse -q --verify "refs/tags/${ONDEWO_SIP_VERSION}"', $spc);
        self::assertStringContainsString('${PACKAGIST_P2_URL}', $spc);
        // The old substring greps matched release/7.1.0-rc1 and 17.1.0.
        self::assertStringNotContainsString('| grep', $spc);
        // The composer.json comparison could not fail: ondewo_release bumped the field right before.
        self::assertStringNotContainsString('composer.json', $spc);
    }

    public function testReleaseVerifyChecksTagNotesRunAndRegistry(): void
    {
        $verify = self::recipe('release_verify_checks');

        self::assertStringContainsString('git ls-remote --exit-code --tags origin "refs/tags/${ONDEWO_SIP_VERSION}"', $verify);
        self::assertStringContainsString('release view "${ONDEWO_SIP_VERSION}"', $verify);
        self::assertStringContainsString('--workflow release.yml --branch "${ONDEWO_SIP_VERSION}"', $verify);
        self::assertStringContainsString('${PACKAGIST_P2_URL}', $verify);
        self::assertStringContainsString('${VERIFY_ATTEMPTS}', $verify);
    }

    public function testTheDevopsCloneIsDeletedBeforeTheReleaseStarts(): void
    {
        $recipe = self::recipe('ondewo_release');
        self::assertSame(['spc'], self::prerequisites('ondewo_release'), 'spc must run before any credential is loaded');

        $clone = strpos($recipe, 'git clone');
        $load = strpos($recipe, 'eval "$$(grep');
        $delete = strpos($recipe, "\tcleanup; \\");
        $release = strpos($recipe, '$(SUBMAKE) release');
        self::assertNotFalse($clone);
        self::assertNotFalse($load);
        self::assertNotFalse($delete, 'ondewo_release must delete the clone (cleanup) before the release starts');
        self::assertNotFalse($release);
        self::assertTrue($clone < $load && $load < $delete && $delete < $release, 'order must be: clone -> load -> delete -> release');

        // ...and on every other way out of the shell.
        self::assertStringContainsString('trap cleanup EXIT', $recipe);
        self::assertMatchesRegularExpression('/cleanup\(\) \{ if \[ -n "\$\$tmp" \]; then rm -rf "\$\$tmp"/', $recipe);
        // The clone goes to a private temp dir outside the repository, never into the repository root.
        self::assertStringContainsString('tmp=`mktemp -d', $recipe);
        self::assertSame(1, preg_match('/git clone [^\n]*"\$\$tmp\//', $recipe));
        // A clone handed over by the driver is used and left alone: only $$tmp is ever deleted.
        self::assertStringContainsString('if [ -n "${DEVOPS_ACCOUNT_DIR}" ]; then', $recipe);
        self::assertMatchesRegularExpression('/^DEVOPS_ACCOUNT_DIR\?=\s*$/m', self::read('Makefile'));
    }

    /**
     * ondewo-sip-api's driver runs both standalone with only DEVOPS_ACCOUNT_DIR set.
     */
    public function testPreflightAndVerifyLoadMissingCredentialsFromTheDevopsDirectory(): void
    {
        foreach (['release_preflight', 'release_verify'] as $target) {
            self::assertSame(
                ['$(LOAD_DEVOPS_CREDENTIALS); $(MAKE) --no-print-directory ' . $target . '_checks'],
                self::commands(self::recipe($target)),
                $target . ' must load the credentials, then run its checks in the same shell',
            );
            self::assertSame([], self::prerequisites($target), $target . ' must not run checks before loading the credentials');
        }

        preg_match('/^LOAD_DEVOPS_CREDENTIALS=(.*?)\n\tfi$/ms', self::read('Makefile'), $load);
        self::assertNotEmpty($load, 'LOAD_DEVOPS_CREDENTIALS is missing');
        $snippet = $load[1];
        // Only from an existing directory, each value only when the environment lacks it, anchored,
        // through `set -a` + eval - never on a command line.
        self::assertStringContainsString('[ -d "${DEVOPS_ACCOUNT_DIR}" ]', $snippet);
        self::assertStringContainsString('set -a', $snippet);
        self::assertStringContainsString('[ -n "$${GITHUB_GH_TOKEN:-}" ] || eval "$$(grep -h -E \'^GITHUB_GH_TOKEN=\'', $snippet);
        self::assertStringContainsString("grep -h -E '^(PACKAGIST_USERNAME|PACKAGIST_API_TOKEN)='", $snippet);
        self::assertStringNotContainsString('echo', $snippet);
    }

    public function testMakeDryRunOfTheDevopsReleaseCannotCloneAnything(): void
    {
        // GNU make executes a recipe line that references $(MAKE) even under -n.
        self::assertStringNotContainsString('$(MAKE)', self::recipe('ondewo_release'));
        self::assertStringNotContainsString('${MAKE}', self::recipe('ondewo_release'));
        self::assertMatchesRegularExpression('/^SUBMAKE=\$\(MAKE\)$/m', self::read('Makefile'));
    }

    public function testCredentialsReachOnlyTheStepsThatUseThem(): void
    {
        $makefile = self::read('Makefile');

        // A bare `export` pushes every variable - credentials included - into composer, phpunit,
        // the pre-commit hooks and every container.
        self::assertSame(0, preg_match('/^export\s*$/m', $makefile), 'no bare `export`');
        self::assertMatchesRegularExpression(
            '/^WITHOUT_CREDENTIALS=env -u GITHUB_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN -u PACKAGIST_USERNAME -u PACKAGIST_API_TOKEN$/m',
            $makefile,
        );
        // The packaging dry run of publish runs composer and php - without a credential.
        self::assertContains('$(WITHOUT_CREDENTIALS) $(MAKE) packagist_dry_run', self::commands(self::recipe('publish')));
    }

    public function testGhIsAuthenticatedPerCallAndNeverLoggedIn(): void
    {
        $recipes = implode("\n", self::recipeLines());

        // `gh auth login` writes the token to ~/.config/gh/hosts.yml, where it outlives the release
        // and is shared by every client released concurrently on the host.
        self::assertStringNotContainsString('gh auth login', $recipes);
        self::assertSame(
            [],
            preg_grep('/\$\{GH_CMD\}/', array_filter(self::recipeLines(), static fn (string $line): bool => !str_contains($line, 'GH_TOKEN="$${GITHUB_GH_TOKEN}" ${GH_CMD}'))),
            'every gh call gets its token as GH_TOKEN="$${GITHUB_GH_TOKEN}" for that call only',
        );
    }

    public function testNoSudoAndNoRootOwnedOutput(): void
    {
        $recipes = implode("\n", self::recipeLines());

        self::assertDoesNotMatchRegularExpression('/\bsudo\b/', $recipes);
        self::assertStringNotContainsString('fix_generated_ownership', self::read('Makefile'));
        // The compiler runs as the invoking user, and the repository root is not one of its volumes.
        $generate = self::recipe('generate_ondewo_protos');
        self::assertStringContainsString('docker run --rm --user $$(id -u):$$(id -g)', $generate);
        self::assertDoesNotMatchRegularExpression('/-v "?\$\(CURDIR\)"?:|\$\{shell pwd\}|\$\(shell pwd\)/', $generate);
    }

    public function testNoImageIsReferencedByAMovingTag(): void
    {
        $makefile = self::read('Makefile');
        self::assertDoesNotMatchRegularExpression('/:latest\b/', implode("\n", self::recipeLines()));
        self::assertMatchesRegularExpression('/^PROTO_COMPILER_IMAGE=ondewo-php-proto-compiler:\$\{PROTO_COMPILER_VERSION\}$/m', $makefile);
        self::assertMatchesRegularExpression('/^ONDEWO_PROTO_COMPILER_GIT_BRANCH=tags\/\d+\.\d+\.\d+$/m', $makefile);

        foreach (glob(dirname(__DIR__) . '/tools/*.Dockerfile') ?: [] as $dockerfile) {
            preg_match_all('/^FROM\s+(\S+)/m', (string) file_get_contents($dockerfile), $from);
            self::assertNotEmpty($from[1]);
            foreach ($from[1] as $image) {
                self::assertMatchesRegularExpression('/^[^:@\s]+:\d[\w.-]*@sha256:[0-9a-f]{64}$/', $image, basename($dockerfile) . ' must pin ' . $image . ' by version AND digest');
            }
        }
    }

    public function testDockerBuildContextsCannotCarryTheDevopsClone(): void
    {
        $root = (string) file_get_contents(dirname(__DIR__) . '/.dockerignore');
        self::assertMatchesRegularExpression('/^ondewo-devops-accounts$/m', $root);
        self::assertMatchesRegularExpression('/^\.git$/m', $root);
        self::assertSame("*\n", preg_replace('/^#.*\n/m', '', (string) file_get_contents(dirname(__DIR__) . '/tools/.dockerignore')));
    }

    /**
     * The recipe of a target, up to the next blank line, without its comment-only lines.
     */
    private static function recipe(string $target): string
    {
        $parts = explode("\n" . $target . ':', self::read('Makefile'), 2);
        self::assertCount(2, $parts, $target . ' is missing');

        return explode("\n\n", $parts[1], 2)[0];
    }

    /**
     * @return list<string>
     */
    private static function prerequisites(string $target): array
    {
        $header = explode("\n", self::recipe($target), 2)[0];
        $header = trim(explode('##', $header, 2)[0]);

        return $header === '' ? [] : preg_split('/\s+/', $header);
    }

    /**
     * The recipe's shell commands, one per logical line, with make's `@` stripped.
     *
     * @return list<string>
     */
    private static function commands(string $recipe): array
    {
        $commands = [];
        $current = null;
        foreach (array_slice(explode("\n", $recipe), 1) as $line) {
            if (!str_starts_with($line, "\t")) {
                continue;
            }
            $text = trim($line);
            if ($current === null) {
                $current = ltrim($text, '@');
            } else {
                $current .= ' ' . $text;
            }
            if (str_ends_with($current, '\\')) {
                $current = rtrim(substr($current, 0, -1));
                continue;
            }
            $commands[] = $current;
            $current = null;
        }

        return $commands;
    }

    /**
     * @return list<string>
     */
    private static function recipeLines(): array
    {
        return array_values(array_filter(
            explode("\n", self::read('Makefile')),
            static fn (string $line): bool => str_starts_with($line, "\t"),
        ));
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/' . $file);
        self::assertIsString($content);

        return $content;
    }
}
