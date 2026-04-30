<?php
declare(strict_types=1);

namespace StarInterop\Stardoc\Fixture;

interface TypeOrderInterface
{
    public const null|int|string MIXED_CONST = null;

    public null|int|string $unionProp { get; }

    public ?Thing $nullableProp { get; }

    /**
     * Property documented with a @var annotation.
     *
     * @var string|int|null
     */
    public null|int|string $annotatedProp { get; }

    /**
     * @param array<int, mixed>|string $b
     */
    public function unionArgs(
        null|bool|Thing $a,
        string|array $b,
    ) : null|int|string;

    public function intersection(Thing&Other $c) : Thing&Other;
}
