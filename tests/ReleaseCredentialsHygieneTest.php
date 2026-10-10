<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Release credentials reach the release recipes and the workflows through the ENVIRONMENT only.
 *
 * /proc/<pid>/cmdline is world-readable, so a token on any command line - docker's, curl's, gh's,
 * make's own or the `sh -c` that make starts for every recipe line - is visible to every user on
 * the host for the life of the process. make expands $(NAME) and ${NAME} BEFORE the shell runs, so
 * a recipe reads a secret as $${NAME}, which the shell expands from the exported environment.
 */
final class ReleaseCredentialsHygieneTest extends TestCase
{
    private const SECRET = '(?:[A-Z0-9_]*(?:TOKEN|PASSWORD|API_KEY|SECRET|PASSPHRASE)[A-Z0-9_]*|PACKAGIST_USERNAME)';

    public function testMakeNeverExpandsASecretIntoARecipeLine(): void
    {
        $leaks = [];
        foreach (self::recipeLines() as $number => $line) {
            // `$(if $(filter-out <placeholder>,$(NAME)),<set>,<unset>)` expands to <set>/<unset>, never to the value.
            $line = preg_replace('/\$\(filter-out [^,]+,\$\(' . self::SECRET . '\)\)/', '', $line);
            if (preg_match('/(?<!\$)\$[({]' . self::SECRET . '[)}]/', $line) === 1) {
                $leaks[] = 'Makefile:' . $number . ': ' . trim($line);
            }
        }

        self::assertSame([], $leaks);
    }

    public function testTheDevopsReleaseHandsTheCredentialsOverTheEnvironment(): void
    {
        $parts = explode("\nrun_release_with_devops:", self::read('Makefile'), 2);
        self::assertCount(2, $parts, 'run_release_with_devops is missing');
        $recipe = explode("\n\n", $parts[1], 2)[0];

        self::assertStringNotContainsString('$(info)', $recipe);
        self::assertStringContainsString('set -a', $recipe);
        self::assertSame(1, preg_match('/\$\(MAKE\) release\s*$/', $recipe));
        // Anchored, so a comment line that mentions a variable name cannot corrupt its value.
        self::assertSame(0, preg_match('/grep\s+(?:-\w+\s+)*[\'"]?(?!\^)[A-Z_]+/', $recipe));
    }

    public function testNoSubMakeGetsASecretAsACommandLineVariable(): void
    {
        $leaks = preg_grep('/(?:\$\(MAKE\)|\bmake)\s[^#\n]*\b' . self::SECRET . '=/', self::recipeLines());

        self::assertSame([], $leaks);
    }

    public function testDockerNeverGetsASecretValueOnItsArgv(): void
    {
        $leaks = preg_grep('/(?:\s-e|--env)[\s=]+' . self::SECRET . '=/', self::recipeLines());

        self::assertSame([], $leaks);
    }

    public function testNoToolGetsASecretAsACommandLineFlag(): void
    {
        $value = '["\']?\$*[({]?' . self::SECRET;
        $leaks = array_merge(
            preg_grep('/(?:--token|--api-key|--password|\s-k|\s-p)[\s=]+' . $value . '/', self::recipeLines()),
            // A -H/--header argument or a ?username=&apiToken= url lands in the process table.
            preg_grep('/(?:-H|--header)\s+["\']?Authorization/i', self::recipeLines()),
            preg_grep('/[?&](?:username|apiToken)=/', self::recipeLines()),
        );

        self::assertSame([], $leaks);
    }

    public function testNoWorkflowRunLineInterpolatesASecret(): void
    {
        $files = glob(dirname(__DIR__) . '/.github/workflows/*.y*ml');
        self::assertNotEmpty($files);

        $leaks = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            $runIndent = null;
            foreach ($lines as $number => $line) {
                $indent = strlen($line) - strlen(ltrim($line));
                if ($runIndent !== null && trim($line) !== '' && $indent <= $runIndent) {
                    $runIndent = null;
                }
                if (preg_match('/^(\s*)(?:- )?run:/', $line, $match) === 1) {
                    $runIndent = strlen($match[1]);
                }
                if ($runIndent !== null && str_contains($line, '${{ secrets.')) {
                    $leaks[] = basename($file) . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $leaks, 'move the secret to the step\'s env: and reference it as $NAME');
    }

    public function testEveryPathTheReleaseRecipeAddsExists(): void
    {
        $makefile = self::read('Makefile');
        $parts = explode("\nrelease:", $makefile, 2);
        self::assertCount(2, $parts, 'release is missing');
        $recipe = explode("\n\n", $parts[1], 2)[0];

        preg_match_all('/^\t(-?)git add (.+)$/m', $recipe, $adds, PREG_SET_ORDER);
        self::assertNotEmpty($adds, 'the release recipe adds nothing');

        preg_match_all('/^([A-Z0-9_]+)\s*:?=\s*(\S+)\s*$/m', $makefile, $assignments, PREG_SET_ORDER);
        $variables = [];
        foreach ($assignments as [, $name, $value]) {
            $variables['${' . $name . '}'] = $value;
            $variables['$(' . $name . ')'] = $value;
        }

        $missing = [];
        foreach ($adds as [$line, $ignored, $paths]) {
            // git rejects the WHOLE add when one pathspec matches nothing, and a leading `-` hides that.
            self::assertSame('', $ignored, 'a failing git add must stop the release: ' . trim($line));
            foreach (preg_split('/\s+/', trim($paths)) as $path) {
                $path = strtr($path, $variables);
                self::assertStringNotContainsString('$', $path, 'unresolved variable in: ' . trim($line));
                if (!file_exists(dirname(__DIR__) . '/' . $path)) {
                    $missing[] = $path;
                }
            }
        }

        self::assertSame([], $missing, 'the release recipe git-adds paths that do not exist');
    }

    /**
     * @return array<int, string> recipe lines keyed by their 1-based line number in the Makefile
     */
    private static function recipeLines(): array
    {
        $lines = [];
        foreach (explode("\n", self::read('Makefile')) as $index => $line) {
            if (str_starts_with($line, "\t")) {
                $lines[$index + 1] = $line;
            }
        }

        return $lines;
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/' . $file);
        self::assertIsString($content);

        return $content;
    }
}
