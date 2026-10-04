<?php

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ModuleDependenciesTest extends TestCase
{
    public function test_modules_respect_layer_and_infrastructure_boundaries(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $count = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($root) + 1);
            [$module, $layer] = explode('/', $relative);
            $traverser = new NodeTraverser(new NameResolver);
            $nodes = $traverser->traverse($parser->parse(file_get_contents($path)) ?? []);
            $names = (new NodeFinder)->findInstanceOf($nodes, Node\Name::class);
            foreach ($names as $name) {
                $dependency = $name->toString();
                if ($layer === 'Domain') {
                    $this->assertDoesNotMatchRegularExpression('/^(Illuminate|Laravel|Symfony)\\\\/', $dependency, $relative);
                }
                if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\(Infrastructure|Presentation)\\\\/', $dependency, $matches)) {
                    $this->assertSame($module, $matches[1], $relative.' imports another module adapter');
                    if ($layer === 'Domain' || $layer === 'Application') {
                        $this->fail($relative.' imports '.$matches[2]);
                    }
                }
            }
            $count++;
        }
        $this->assertGreaterThan(0, $count);
    }
}
