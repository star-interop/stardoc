<?php
declare(strict_types=1);

namespace StarInterop\Stardoc\Fixture;

interface TypeOrderInterface
{
    public const string|int|null MIXED_CONST = null;

    public string|int|null $unionProp { get; }

    public ?Thing $nullableProp { get; }

    /**
     * Property documented with a @var annotation.
     *
     * @var string|int|null
     */
    public string|int|null $annotatedProp { get; }

    /**
     * @param array<int, mixed>|string $b
     */
    public function unionArgs(Thing|bool|null $a, array|string $b) : int|string|null;

    public function intersection(Thing&Other $c) : Thing&Other;
}
