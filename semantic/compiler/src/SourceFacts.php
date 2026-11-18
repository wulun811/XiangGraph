<?php

declare(strict_types=1);

namespace XiangGraph\G1;

/**
 * Extracts a deliberately small, declared subset of Symfony source facts.
 *
 * The extractor never invents a relationship. It records literal import
 * directives, the files those directives currently match, and provenance
 * (file, line, source hash). Consumer meaning (which facts an import affects)
 * comes from the explicit consumer contract, not from this scanner.
 */
final class SourceFacts
{
    public const EXTRACTOR_VERSION = 'symfony-subset-0.1';

    /**
     * @param array<string, string> $files
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public static function scan(array $files, array $contract, string $workspaceId): array
    {
        ksort($files);
        $imports = [];
        $unsupported = [];
        $seenPatternFacts = [];

        foreach ($files as $path => $content) {
            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            if ($extension === 'php') {
                $scan = PhpImportScanner::extractImports($content, $path);
                $found = $scan['imports'];
                foreach ($scan['unsupported'] as $item) {
                    $unsupported[] = $item + ['file' => $path];
                }
            } elseif ($extension === 'yaml' || $extension === 'yml') {
                $found = YamlSubset::extractImports($content, $path);
            } else {
                continue;
            }

            foreach ($found as $import) {
                $resource = (string) $import['resource'];
                $invalid = self::unsupportedReason($resource);
                if ($invalid !== null) {
                    $unsupported[] = [
                        'file' => $path,
                        'line' => $import['line'],
                        'reason' => $invalid,
                        'snippet' => $resource,
                    ];
                    continue;
                }

                $kind = self::classify($path, $contract);
                if ($kind === null) {
                    $unsupported[] = [
                        'file' => $path,
                        'line' => $import['line'],
                        'reason' => 'no consumer contract mapping for this import source',
                        'snippet' => $resource,
                    ];
                    continue;
                }

                $resolved = self::resolve($path, $resource);
                $hasGlob = self::hasGlob($resolved);
                $glob = $hasGlob ? $resolved : self::exactOrDirectoryGlob($resolved, $files);
                if ($glob === null) {
                    $unsupported[] = [
                        'file' => $path,
                        'line' => $import['line'],
                        'reason' => 'import target is outside the controlled workspace or does not exist',
                        'snippet' => $resource,
                    ];
                    continue;
                }

                $baseFact = 'symfony.import.' . $path . '#' . $import['line'];
                $patternFact = $baseFact;
                $suffix = 1;
                while (isset($seenPatternFacts[$patternFact])) {
                    ++$suffix;
                    $patternFact = $baseFact . '.' . $suffix;
                }
                $seenPatternFacts[$patternFact] = true;

                $kindSpec = $contract['kinds'][$kind] ?? [];
                $coverage = $hasGlob ? (string) ($kindSpec['coverage'] ?? 'closed') : 'closed';
                $sourceRef = $path . ':' . $import['line'];
                $sourceHash = hash('sha256', $content);
                $imports[] = [
                    'pattern_id' => 'pat.' . substr(hash('sha256', $sourceRef . '|' . $resource), 0, 12),
                    'pattern_fact' => $patternFact,
                    'source_ref' => $sourceRef,
                    'source_file' => $path,
                    'source_line' => $import['line'],
                    'source_hash' => $sourceHash,
                    'version' => 'v1:' . substr(hash('sha256', $sourceRef . '|' . $resource . '|' . $sourceHash), 0, 16),
                    'extractor' => $import['via'],
                    'resource_raw' => $resource,
                    'resolved_glob' => $glob,
                    'has_glob' => $hasGlob,
                    'kind' => $kind,
                    'depends_on' => array_values($kindSpec['depends_on'] ?? []),
                    'coverage' => $coverage,
                    'certainty' => $hasGlob ? 'confirmed' : 'confirmed',
                    'snippet' => trim((string) preg_split('/\R/', $content)[$import['line'] - 1] ?? ''),
                ];
            }
        }

        $imports = self::decorateMatches($imports, $files);

        $services = self::serviceIds($files);
        $routeFiles = [];
        $routeNames = [];
        foreach ($imports as $import) {
            if ($import['kind'] !== 'routing_import') {
                continue;
            }
            foreach ($import['observed_matches'] as $match) {
                $routeFiles[$match] = true;
                foreach (self::routeNamesIn($files[$match] ?? '', $match) as $name) {
                    $routeNames[$name] = true;
                }
            }
        }
        $routeFiles = array_keys($routeFiles);
        sort($routeFiles);
        $routeNames = array_keys($routeNames);
        sort($routeNames);

        $serviceFiles = [];
        foreach ($imports as $import) {
            if ($import['kind'] !== 'di_import') {
                continue;
            }
            foreach ($import['observed_matches'] as $match) {
                $serviceFiles[$match] = true;
            }
        }
        $serviceFiles = array_keys($serviceFiles);
        sort($serviceFiles);

        $facts = [];
        $hashes = [];
        foreach ($files as $path => $content) {
            $hash = hash('sha256', $content);
            $hashes[$path] = $hash;
            $facts['file:' . $path] = $hash;
        }
        foreach ($imports as $import) {
            $facts[$import['pattern_fact']] = $import['resource_raw'];
        }

        $docsParts = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'docs/')) {
                $docsParts[$path] = hash('sha256', $content);
            }
        }
        ksort($docsParts);

        $index = [
            'di' => hash('sha256', json_encode($services, \JSON_THROW_ON_ERROR)),
            'routes' => hash('sha256', json_encode($routeNames, \JSON_THROW_ON_ERROR)),
            'docs' => hash('sha256', json_encode($docsParts, \JSON_THROW_ON_ERROR)),
            'tree' => FileTree::treeHash($files),
        ];

        return [
            'workspace_id' => $workspaceId,
            'extractor_version' => self::EXTRACTOR_VERSION,
            'files' => $files,
            'hashes' => $hashes,
            'facts' => $facts,
            'imports' => $imports,
            'patterns' => array_map(static fn (array $import): array => [
                'id' => $import['pattern_id'],
                'pattern_fact' => $import['pattern_fact'],
                'glob' => $import['resolved_glob'],
                'fact_prefix' => 'file:',
                'depends_on' => $import['depends_on'],
                'observed_matches' => $import['observed_matches'],
                'coverage' => $import['coverage'],
                'status' => 'registered',
                'source' => 'symfony-source:' . $import['source_ref'],
                'certainty' => $import['certainty'],
                'version' => $import['version'],
            ], $imports),
            'unsupported' => $unsupported,
            'services' => $services,
            'service_files' => $serviceFiles,
            'routes' => $routeNames,
            'route_files' => $routeFiles,
            'index' => $index,
            'snapshot_hash' => hash('sha256', json_encode([
                'workspace' => $workspaceId,
                'extractor' => self::EXTRACTOR_VERSION,
                'tree' => $index['tree'],
                'imports' => array_map(static fn (array $i): array => [
                    $i['pattern_fact'],
                    $i['resource_raw'],
                    $i['resolved_glob'],
                    $i['observed_matches'],
                ], $imports),
                'unsupported' => $unsupported,
            ], \JSON_THROW_ON_ERROR)),
        ];
    }

    private static function unsupportedReason(string $resource): ?string
    {
        if ($resource === '' || str_contains($resource, "\n")) {
            return 'empty or multiline resource';
        }
        if (str_contains($resource, '$') || str_contains($resource, '%')) {
            return 'resource contains a variable or parameter placeholder';
        }
        if (str_starts_with($resource, '@')) {
            return 'bundle/namespace resources are not part of the supported subset';
        }
        if (preg_match('/\{[A-Z][A-Za-z0-9_]*\}/', $resource) === 1) {
            return 'resource contains an unresolved placeholder such as {F}';
        }
        if (str_starts_with($resource, '/')) {
            return 'absolute paths are outside the controlled workspace';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private static function classify(string $path, array $contract): ?string
    {
        foreach ($contract['import_kinds'] ?? [] as $mapping) {
            $glob = (string) ($mapping['source_glob'] ?? '');
            if ($glob !== '' && fnmatch($glob, $path, \FNM_PATHNAME) === true) {
                return (string) ($mapping['kind'] ?? '');
            }
        }

        return null;
    }

    private static function resolve(string $sourceFile, string $resource): string
    {
        if (str_starts_with($resource, './')) {
            $resource = substr($resource, 2);
        }
        $base = \dirname($sourceFile);
        $joined = $base === '.' ? $resource : $base . '/' . $resource;

        $parts = [];
        foreach (explode('/', $joined) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function hasGlob(string $path): bool
    {
        return strpbrk($path, '*?{[') !== false;
    }

    /**
     * @param array<string, string> $files
     */
    private static function exactOrDirectoryGlob(string $resolved, array $files): ?string
    {
        if (isset($files[$resolved])) {
            return $resolved;
        }
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, $resolved . '/')) {
                return $resolved . '/**/*';
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $imports
     * @param array<string, string> $files
     * @return list<array<string, mixed>>
     */
    private static function decorateMatches(array $imports, array $files): array
    {
        foreach ($imports as &$import) {
            $matches = [];
            foreach (array_keys($files) as $path) {
                $candidatePath = $path;
                if (str_starts_with($candidatePath, 'file:')) {
                    $candidatePath = substr($candidatePath, 5);
                }
                if (self::globMatches((string) $import['resolved_glob'], $candidatePath)) {
                    $matches[] = $candidatePath;
                }
            }
            sort($matches);
            $import['observed_matches'] = array_values(array_unique($matches));
        }
        unset($import);

        return $imports;
    }

    /**
     * @param array<string, string> $files
     * @return list<string>
     */
    private static function serviceIds(array $files): array
    {
        $ids = [];
        foreach ($files as $path => $content) {
            if (!str_starts_with($path, 'config/services') || !preg_match('/\.ya?ml$/', $path)) {
                continue;
            }
            $lines = preg_split('/\R/', $content) ?: [];
            $inServices = false;
            $servicesIndent = 0;
            foreach ($lines as $line) {
                if (preg_match('/^(\s*)services:\s*$/', $line, $match) === 1) {
                    $inServices = true;
                    $servicesIndent = \strlen($match[1]);
                    continue;
                }
                if (!$inServices) {
                    continue;
                }
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $indent = \strlen($line) - \strlen(ltrim($line));
                if ($indent <= $servicesIndent) {
                    $inServices = false;
                    continue;
                }
                if ($indent === $servicesIndent + 4
                    && preg_match('/^([A-Za-z0-9_.\\\\-]+):(?:\s|$)/', trim($line), $idMatch) === 1
                    && !str_starts_with($idMatch[1], '_')) {
                    $ids[$idMatch[1]] = true;
                }
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function routeNamesIn(string $content, string $path): array
    {
        $names = [];
        if (preg_match('/\.php$/', $path) === 1) {
            if (preg_match_all('/->(?:add|get|post|put|patch|delete|options|match)\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches) !== false) {
                foreach ($matches[1] as $name) {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $current = null;
        $hasPath = false;
        $hasResource = false;
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_.-]+):\s*$/', $line, $match) === 1) {
                if ($current !== null && $hasPath && !$hasResource) {
                    $names[] = $current;
                }
                $current = $match[1];
                $hasPath = false;
                $hasResource = false;
                continue;
            }
            if ($current !== null && preg_match('/^\s+path:\s*\S+/', $line) === 1) {
                $hasPath = true;
            }
            if ($current !== null && preg_match('/^\s+resource:\s*\S+/', $line) === 1) {
                $hasResource = true;
            }
        }
        if ($current !== null && $hasPath && !$hasResource) {
            $names[] = $current;
        }

        return array_values(array_unique($names));
    }

    public static function globMatches(string $glob, string $path): bool
    {
        $quoted = preg_quote($glob, '#');
        $quoted = str_replace('\*\*/', '(?:.*/)?', $quoted);
        $quoted = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $quoted);
        $quoted = preg_replace('#\\\{([^}]+)\\\}#', '($1)', $quoted) ?? $quoted;

        return preg_match('#^' . $quoted . '$#', $path) === 1;
    }
}
