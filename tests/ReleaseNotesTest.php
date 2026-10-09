<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The GitHub release body is sliced out of RELEASE.md by the Makefile's CURRENT_RELEASE_NOTES:
 * from the `Release ONDEWO SIP PHP Client <version>` heading to the next `*****` line. A heading
 * spelled any other way gives an empty slice, and `gh release create -n ""` then publishes a
 * release without notes and without an error.
 */
final class ReleaseNotesTest extends TestCase
{
    private const HEADING = 'Release ONDEWO SIP PHP Client ';

    private const VERSION_VARIABLE = 'ONDEWO_SIP_VERSION';

    public function testTheMakefileSlicesTheHeadingThisTestChecks(): void
    {
        self::assertStringContainsString(
            "perl -ne 'print if /" . self::HEADING . '${' . self::VERSION_VARIABLE . "}/../^\\*{5}/'",
            self::read('Makefile'),
        );
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
        $starts = array_keys(preg_grep('/' . preg_quote(self::HEADING . $match[1], '/') . '/', $lines));
        self::assertNotEmpty($starts, 'RELEASE.md has no section for ' . $match[1]);

        $end = self::sliceEnd($lines, $starts[0]);
        self::assertNotNull($end);
        $body = array_filter(array_slice($lines, $starts[0] + 1, $end - $starts[0] - 1), static fn ($l) => trim($l) !== '');
        self::assertGreaterThan(1, count($body));
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
