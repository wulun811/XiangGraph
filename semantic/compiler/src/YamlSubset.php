<?php

declare(strict_types=1);

namespace XiangGraph\G1;

/**
 * Minimal line-oriented reader for the YAML subset used by the vertical slice.
 *
 * Supported:
 *   imports:
 *       - { resource: 'services/*.yaml' }
 *       - resource: 'services/*.yaml'
 *   route_name:
 *       resource: 'routes/*.yaml'
 *       type: glob
 *
 * Not supported: anchors, merge keys, multi-line scalars, dynamic expressions,
 * or any resource that is not a literal. Unsupported constructs are reported,
 * never guessed.
 */
final class YamlSubset
{
    /**
     * @return list<array{resource: string, line: int, via: string, type: ?string}>
     */
    public static function extractImports(string $content, string $file): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $imports = [];
        $inImports = false;
        $importsIndent = 0;

        foreach ($lines as $offset => $line) {
            $lineNo = $offset + 1;

            if (preg_match('/^(\s*)imports:\s*$/', $line, $match) === 1) {
                $inImports = true;
                $importsIndent = \strlen($match[1]);
                continue;
            }

            if ($inImports) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $indent = \strlen($line) - \strlen(ltrim($line));
                if ($indent <= $importsIndent && !str_starts_with($trimmed, '-')) {
                    $inImports = false;
                } elseif (preg_match('/^\s*-\s*(?:\{\s*)?resource:\s*(.+?)\s*(?:\}|$)/', $line, $match) === 1) {
                    $type = null;
                    if (preg_match('/type:\s*([^,}]+)/', $line, $typeMatch) === 1) {
                        $type = trim($typeMatch[1], " \t'\"");
                    }
                    $imports[] = [
                        'resource' => self::unquote($match[1]),
                        'line' => $lineNo,
                        'via' => 'imports',
                        'type' => $type,
                    ];
                    continue;
                }
            }

            // A routing entry resource, or an explicit service resource. This
            // is intentionally narrower than a YAML parser: only a literal
            // value at an indented `resource:` key is recognized.
            if (!$inImports && preg_match('/^(\s{2,})resource:\s*(.+?)\s*$/', $line, $match) === 1) {
                $raw = self::unquote($match[2]);
                if ($raw === '' || str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
                    continue;
                }
                $type = null;
                for ($lookahead = $offset + 1; $lookahead < min(\count($lines), $offset + 5); ++$lookahead) {
                    if (preg_match('/^\s+type:\s*(\S+)\s*$/', $lines[$lookahead], $typeMatch) === 1) {
                        $type = trim($typeMatch[1], " \t'\"");
                        break;
                    }
                }
                $imports[] = [
                    'resource' => $raw,
                    'line' => $lineNo,
                    'via' => 'resource',
                    'type' => $type,
                ];
            }
        }

        // A resource may be observed twice when it sits inside a recognized
        // imports block; keep the first, explicit import form only.
        $seen = [];
        $unique = [];
        foreach ($imports as $import) {
            $key = $import['line'] . '|' . $import['resource'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $import;
        }

        return $unique;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (($first === "'" || $first === '"') && str_ends_with($value, $first)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
