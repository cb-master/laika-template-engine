<?php


namespace Laika\Template;


class Filter
{
    /**
     * Filter Maps
     * @var array Default Maps
     */
    protected static array $map = [
        'upper' => 'strtoupper',
        'lower' => 'strtolower',
        'escape' => 'htmlspecialchars',
        'raw' => null,
        'length' => 'strlen',
    ];


    /**
     * @var string $name Resolve Filter Map
     * @return ?callable
     */
    public static function resolve(string $name): ?callable
    {
        if (! isset(self::$map[$name])) {
            return null;
        }


        $fn = self::$map[$name];
        if ($fn === null) {
            return null; // raw: special-case handled in compiler
        }


        return $fn;
    }


    /**
     * @param string $name Filter Map Name
     * @param callable $callable Function Name or Callable as Filter
     * @return void
     */
    public static function add(string $name, callable $callable): void
    {
        self::$map[$name] = $callable;
    }
}
