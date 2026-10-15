<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The GitHub release body is sliced out of RELEASE.md by the Makefile's CURRENT_RELEASE_NOTES:
 * from the line that is EXACTLY `#... Release ONDEWO SIP PHP Client <version>` to the next `*****`
 * line. A heading
 * spelled any other way gives an empty slice, and `gh release create -n ""` then publishes a
 * release without notes and without an error.
 */
final class ReleaseNotesTest extends TestCase
{
    private const HEADING = 'Release ONDEWO SIP PHP Client ';

    private const VERSION_VARIABLE = 'ONDEWO_SIP_VERSION';

    public function testTheMakefileSlicesTheHeadingThisTestChecks(): void
    {
        // Anchored on both ends, with the version quoted, so 9.1.1 can never slice the 9.1.10 entry.
        self::assertSame(
            "print if /^#+ " . self::HEADING . '\\Q${' . self::VERSION_VARIABLE . '}\\E$/../^\\*{5}/',
            self::sliceProgram('${' . self::VERSION_VARIABLE . '}'),
        );
    }

    public function testTheSliceOfAVersionNeverPicksUpALongerVersionsEntry(): void
    {
        $fixture = implode("\n", [
            '# Release History',
            '',
            '*****************',
            '',
            '## ' . self::HEADING . '9.1.10',
            '',
            '* the 9.1.10 notes',
            '',
            '*****************',
            '',
            '## ' . self::HEADING . '9.1.1',
            '',
            '* the 9.1.1 notes',
            '',
            '*****************',
            '',
            '## ' . self::HEADING . '9.1.0 (draft)',
            '',
            '* not a heading of 9.1.0',
            '',
            '*****************',
            '',
        ]);

        $slice = self::runSlice('9.1.1', $fixture);
        self::assertStringContainsString('the 9.1.1 notes', $slice);
        self::assertStringNotContainsString('9.1.10', $slice);

        self::assertStringContainsString('the 9.1.10 notes', self::runSlice('9.1.10', $fixture));
        // A heading with anything after the version is not that version's heading.
        self::assertSame('', self::runSlice('9.1.0', $fixture));
        // The dots are literal: 9x1y1 must not match 9.1.1.
        self::assertSame('', self::runSlice('9x1y1', str_replace('9.1.1', '9x1y1z', $fixture)));
    }

    public function testEveryReleaseHeadingUsesTheSpellingTheMakefileSlices(): void
    {
        $headings = preg_grep('/^## Release /', self::lines());

        self::assertNotEmpty($headings);
        foreach ($headings as $heading) {
            self::assertMatchesRegularExpression('/^## ' . self::HEADING . '\d+\.\d+\.\d+$/', $heading);
        }
    }

    public function testEverySectionEndsAtASeparatorBeforeTheNextHeading(): void
    {
        $lines = self::lines();
        foreach (preg_grep('/^## Release /', $lines) as $start => $heading) {
            $end = self::sliceEnd($lines, $start);
            self::assertNotNull($end, $heading . ' has no ***** separator after it');
            self::assertSame([], preg_grep('/^## Release /', array_slice($lines, $start + 1, $end - $start)), $heading);
        }
    }

    public function testTheCurrentVersionHasNonEmptyReleaseNotes(): void
    {
        self::assertSame(1, preg_match('/^' . self::VERSION_VARIABLE . '=(\S+)$/m', self::read('Makefile'), $match));
        $lines = self::lines();
        $starts = array_keys(preg_grep('/^#+ ' . preg_quote(self::HEADING . $match[1], '/') . '$/', $lines));
        self::assertNotEmpty($starts, 'RELEASE.md has no section for ' . $match[1]);

        $end = self::sliceEnd($lines, $starts[0]);
        self::assertNotNull($end);
        $body = array_filter(array_slice($lines, $starts[0] + 1, $end - $starts[0] - 1), static fn ($l) => trim($l) !== '');
        self::assertGreaterThan(1, count($body));
    }

    /**
     * The perl program of the Makefile's CURRENT_RELEASE_NOTES, as the shell receives it: make
     * unescapes `\#` and `$$`, and expands the version variable.
     */
    private static function sliceProgram(string $version): string
    {
        self::assertSame(
            1,
            preg_match("/^CURRENT_RELEASE_NOTES=.*\\n\\t\\| perl -ne '([^']+)'`$/m", self::read('Makefile'), $match),
            'CURRENT_RELEASE_NOTES no longer has the `cat RELEASE.md | perl -ne \'...\'` shape this test runs',
        );

        return strtr($match[1], ['\\#' => '#', '$$' => '$', '${' . self::VERSION_VARIABLE . '}' => $version]);
    }

    private static function runSlice(string $version, string $releaseMd): string
    {
        $process = proc_open(
            ['perl', '-ne', self::sliceProgram($version)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'perl could not be started');
        fwrite($pipes[0], $releaseMd);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'perl failed: ' . $err);

        return $out;
    }

    /**
     * @param list<string> $lines
     */
    private static function sliceEnd(array $lines, int $start): ?int
    {
        for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
            if (preg_match('/^\*{5}/', $lines[$i]) === 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function lines(): array
    {
        return explode("\n", str_replace("\r\n", "\n", self::read('RELEASE.md')));
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/' . $file);
        self::assertIsString($content);

        return $content;
    }
}
