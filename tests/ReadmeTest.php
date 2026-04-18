<?php
declare(strict_types=1);

namespace StarInterop\Stardoc;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

class ReadmeTest extends TestCase
{
    private function render() : string
    {
        $classLoader = require __DIR__ . '/../vendor/autoload.php';
        assert($classLoader instanceof ClassLoader);

        $readme = new Readme(
            $classLoader,
            'StarInterop\\Stardoc\\Fixture\\',
            __DIR__ . '/Fixture',
            ['TypeOrderInterface'],
            __DIR__ . '/Fixture/template.md',
        );

        return $readme();
    }

    public function testReflectedUnionsFollowPhpStylerOrder() : void
    {
        $output = $this->render();

        $this->assertStringContainsString(
            'public const null|int|string MIXED_CONST',
            $output,
        );
        $this->assertStringContainsString(
            'public null|int|string $unionProp',
            $output,
        );
        $this->assertStringContainsString('null|bool|Thing $a', $output);
        $this->assertStringContainsString('string|array $b', $output);
        $this->assertStringContainsString(') : null|int|string;', $output);
    }

    public function testNullableNamedTypePassesThrough() : void
    {
        $output = $this->render();
        $this->assertStringContainsString('public ?Thing $nullableProp', $output);
    }

    public function testIntersectionTypePassesThrough() : void
    {
        $output = $this->render();
        $this->assertStringContainsString('intersection(Thing&Other $c)', $output);
        $this->assertStringContainsString(') : Thing&Other;', $output);
    }

    public function testDocblockAnnotationPreservesAuthorOrder() : void
    {
        $output = $this->render();
        $this->assertStringContainsString(
            'public string|int|null $annotatedProp',
            $output,
        );
    }
}
