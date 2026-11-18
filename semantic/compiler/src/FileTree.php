<?php

declare(strict_types=1);

namespace XiangGraph\G1;

final class FileTree
{
    /**
     * @return array<string, string> relative path => content
     */
    public static function read(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException('workspace directory does not exist: ' . $directory);
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), \strlen($directory) + 1));
            if (str_starts_with($path, '.git/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $files[$path] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /**
     * Apply an overlay directory and an explicit delete list to a base tree.
     *
     * @param array<string, string> $base
     * @return array<string, string>
     */
    public static function overlay(array $base, string $overlayDirectory, array $deletes = []): array
    {
        if ($overlayDirectory !== '' && is_dir($overlayDirectory)) {
            foreach (self::read($overlayDirectory) as $path => $content) {
                $base[$path] = $content;
            }
        }
        foreach ($deletes as $path) {
            unset($base[(string) $path]);
        }
        ksort($base);

        return $base;
    }

    /**
     * @param array<string, string> $files
     */
    public static function treeHash(array $files): string
    {
        $rows = [];
        foreach ($files as $path => $content) {
            $rows[] = $path . ':' . hash('sha256', $content);
        }

        return hash('sha256', implode("\n", $rows));
    }
}
