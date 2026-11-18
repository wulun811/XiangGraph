<?php

declare(strict_types=1);

namespace XiangGraph\G1;

/**
 * Scans PHP source for literal import() resources.
 *
 * Supported:  $routes->import('routes/*.yaml');
 *             $loader->import(resource: 'services/*.yaml');
 * Not supported: concatenation, variables, constants, arrays, or multiline
 * expressions. Those are reported as unsupported instead of being guessed.
 */
final class PhpImportScanner
{
    /**
     * @return array{
     *   imports: list<array{resource: string, line: int, via: string, type: ?string}>,
     *   unsupported: list<array{line: int, reason: string, snippet: string}>
     * }
     */
    public static function extractImports(string $content, string $file): array
    {
        $imports = [];
        $unsupported = [];
        $lines = preg_split('/\R/', $content) ?: [];
        $pattern = '/->import\(\s*(?:resource:\s*)?([\'"])([^\'"]+)\1\s*[,)]/';

        foreach ($lines as $offset => $line) {
            $lineNo = $offset + 1;
            if (!str_contains($line, '->import(')) {
                continue;
            }

            $count = preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);
            if ($count === false || $count === 0) {
                $unsupported[] = [
                    'line' => $lineNo,
                    'reason' => 'import() argument is not a single quoted/string literal',
                    'snippet' => trim($line),
                ];
                continue;
            }

            foreach ($matches[2] as $match) {
                $resource = $match[0];
                if (str_contains($resource, '$') || str_contains($resource, '%')) {
                    $unsupported[] = [
                        'line' => $lineNo,
                        'reason' => 'import() literal contains a variable or parameter placeholder',
                        'snippet' => trim($line),
                    ];
                    continue;
                }
                $imports[] = [
                    'resource' => $resource,
                    'line' => $lineNo,
                    'via' => 'php_import_literal',
                    'type' => null,
                ];
            }
        }

        return ['imports' => $imports, 'unsupported' => $unsupported];
    }
}
