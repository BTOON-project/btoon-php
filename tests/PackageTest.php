<?php

declare(strict_types=1);

namespace BTOON\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What Composer installs from Packagist: GitHub's dist archive, which `git archive` builds
 * and which honors export-ignore in .gitattributes. Without it, the archive shipped tests/
 * (with 260 KB of vectors), bench/, stubs/ and .github/: 542 KB instead of 154 KB
 * (review 2026-11-13).
 */
final class PackageTest extends TestCase
{
    /** Top-level files that ship; everything else must be under src/ or examples/. */
    private const SHIPPED_FILES = ['CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json'];

    public function testTheDistArchiveHoldsTheLibraryOnly(): void
    {
        $files = self::archivedFiles();
        self::assertContains('composer.json', $files);
        self::assertContains('src/Decoder.php', $files);
        self::assertContains('src/functions.php', $files);
        foreach ($files as $file) {
            self::assertTrue(
                \in_array($file, self::SHIPPED_FILES, true) || str_starts_with($file, 'src/') || str_starts_with($file, 'examples/'),
                "the dist archive ships $file",
            );
        }
    }

    /**
     * The files `git archive` puts in an archive of HEAD, with the working tree's
     * .gitattributes.
     *
     * @return list<string>
     */
    private static function archivedFiles(): array
    {
        $root = \dirname(__DIR__);
        if (!file_exists("$root/.git")) {
            self::markTestSkipped('not a git checkout');
        }
        $git = null;
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable("$directory/git")) {
                $git = "$directory/git";
                break;
            }
        }
        if ($git === null) {
            self::markTestSkipped('git is not installed');
        }
        $process = proc_open(
            [$git, '-C', $root, 'archive', '--worktree-attributes', '--format=tar', 'HEAD'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            self::markTestSkipped('cannot run git');
        }
        $tar = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            self::markTestSkipped('git archive failed: ' . trim($error));
        }

        // A ustar archive: 512-byte headers, each followed by its file's data in 512-byte blocks.
        $files = [];
        for ($offset = 0; $offset + 512 <= \strlen($tar); $offset += 512 + intdiv($size + 511, 512) * 512) {
            $header = substr($tar, $offset, 512);
            if (trim($header, "\0") === '') {
                break;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            if ($header[156] === '0' || $header[156] === "\0") {
                $files[] = $prefix === '' ? $name : "$prefix/$name";
            }
        }

        return $files;
    }
}
