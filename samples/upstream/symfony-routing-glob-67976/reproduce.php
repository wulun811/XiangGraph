<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\FileLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Minimal executable harness over the frozen upstream merge commit.
 *
 * It is not the upstream PHPUnit test suite. It exercises the production
 * classes changed by Symfony PR #67976 on the exact merge commit.
 */
final class TestFileLoader extends FileLoader
{
    public function load(mixed $resource, ?string $type = null): mixed
    {
        return $resource;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return false;
    }
}

$tmp = sys_get_temp_dir() . '/xg-sym67976-' . bin2hex(random_bytes(4));
mkdir($tmp . '/yaml', 0777, true);
mkdir($tmp . '/glob', 0777, true);

$checks = 0;
function ok(string $name, bool $condition, string $detail = ''): void
{
    global $checks;
    if (!$condition) {
        fwrite(\STDERR, "FAIL {$name} {$detail}\n");
        exit(1);
    }
    ++$checks;
    echo "PASS {$name}\n";
}

// 1. DependencyInjection: a glob matching no files is still tracked.
$container = new ContainerBuilder();
$loader = new TestFileLoader($container, new FileLocator($tmp));
$loader->import($tmp . '/yaml/*.missing');
$resources = $container->getResources();
ok('DI glob with zero matches is tracked', count($resources) === 1 && $resources[0] instanceof GlobResource);
ok('DI glob resource keeps pattern', (string) $resources[0] === (string) new GlobResource($tmp . '/yaml', '/*.missing', false));

// 2. Routing configurator: a zero-match import is still tracked.
$collection = new RouteCollection();
$routes = new RoutingConfigurator($collection, new PhpFileLoader(new FileLocator()), __FILE__, __FILE__);
$routes->import($tmp . '/glob/*.missing');
$resources = $collection->getResources();
ok('Routing zero-match import is tracked', count($resources) === 1 && $resources[0] instanceof GlobResource);
ok('Routing zero-match pattern is kept', (string) $resources[0] === (string) new GlobResource($tmp . '/glob', '/*.missing', false));

// 3. Routing: a new file matching the glob is discovered on the next load.
file_put_contents($tmp . '/glob/bar.yml', "bar_route:\n  path: /bar\n  defaults: { _controller: 'AppBundle:Bar:view' }\n");
file_put_contents($tmp . '/glob/routes.yml', "import:\n  resource: 'ba?.yml'\n");
$first = (new YamlFileLoader(new FileLocator([$tmp . '/glob'])))->load('routes.yml');
ok('First load sees bar route', $first->get('bar_route') !== null);
ok('First load does not see baz route', $first->get('baz_route') === null);
file_put_contents($tmp . '/glob/baz.yml', "baz_route:\n  path: /baz\n  defaults: { _controller: 'AppBundle:Baz:view' }\n");
$second = (new YamlFileLoader(new FileLocator([$tmp . '/glob'])))->load('routes.yml');
ok('New glob-matched file is discovered', $second->get('baz_route') !== null);
ok('Glob resource is recorded after new match', count($second->getResources()) > 0);

echo "OK: {$checks} upstream-code behavior checks passed\n";
