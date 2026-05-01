<?php
declare(strict_types=1);

namespace StarInterop\Stardoc;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/**
 * @phpstan-type Reflectable ReflectionClass<object>|ReflectionClassConstant|ReflectionProperty|ReflectionMethod
 */
class Readme
{
    /** @var list<string> */
    protected array $list = [];

    /** @var list<string> */
    protected array $docs = [];

    /**
     * @param list<string> $interfaces
     */
    public function __construct(
        protected ClassLoader $classLoader,
        protected string $namespace,
        protected string $directory,
        protected array $interfaces,
        protected string $template,
    ) {
    }

    public function __invoke() : string
    {
        $this->classLoader->addPsr4($this->namespace, $this->directory);

        foreach ($this->interfaces as $interface) {
            /** @var class-string $fqcn */
            $fqcn = $this->namespace . $interface;
            $class = new ReflectionClass($fqcn);
            $this->addInterface($class);
            $this->addConstants($class);
            $this->addProperties($class);
            $this->addMethods($class);
        }

        $list = implode(PHP_EOL . PHP_EOL, $this->list) . PHP_EOL;
        $docs = implode(PHP_EOL, $this->docs) . PHP_EOL;
        return $this->render($list, $docs);
    }

    protected function render(string $list, string $docs) : string
    {
        $readme = file_get_contents($this->template);

        if ($readme === false) {
            throw new RuntimeException(
                "Could not read template at {$this->template}",
            );
        }

        $readme = str_replace('{{= list }}', $list, $readme);
        $readme = str_replace('{{= docs }}', $docs, $readme);
        $readme = (string) preg_replace('/^\s+$/m', '', $readme);
        $readme = (string) preg_replace('/^- Methods:\n\n###/m', "###", $readme);
        return $readme;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    protected function formattedClassName(ReflectionClass $class) : string
    {
        return "_"
            . $this->stripNamespace($class->getNamespaceName(), $class->getName())
            . "_";
    }

    /**
     * @param ReflectionClass<object> $class
     */
    protected function addInterface(ReflectionClass $class) : void
    {
        $this->addList($class);
        $subtitle = "### " . $this->formattedClassName($class);
        $this->docs[] = $subtitle;
        $this->docs[] = "";
        $this->addNarrative($class, '', '');
    }

    /**
     * @param Reflectable $r
     */
    protected function addList(
        ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionMethod $r,
    ) : void
    {
        $comment = $this->cleanComment($r);
        $blankLine = strpos($comment, PHP_EOL . PHP_EOL);

        $item = $blankLine === false ? $comment : substr($comment, 0, $blankLine);

        $this->list[] = '- ' . str_replace(PHP_EOL, ' ', $item);
    }

    /**
     * @param Reflectable $r
     */
    protected function addNarrative(
        ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionMethod $r,
        string $indent,
        string $prefix,
    ) : void
    {
        $comment = $this->cleanComment($r);
        $pos = strpos($comment, PHP_EOL . "@");

        if ($pos !== false) {
            $comment = substr($comment, 0, $pos);
        }

        $comment = $prefix . $comment;
        $lines = explode(PHP_EOL, $comment);

        foreach ($lines as &$line) {
            $this->docs[] = $indent . $line;
        }

        $this->docs[] = "";
    }

    /**
     * @param ReflectionClass<object> $class
     */
    protected function addConstants(ReflectionClass $class) : void
    {
        $constants = $class->getReflectionConstants(
            ReflectionClassConstant::IS_PUBLIC,
        );

        foreach ($constants as $i => $constant) {
            if ($constant->getDeclaringClass() != $class) {
                unset($constants[$i]);
            }
        }

        if (! $constants) {
            return;
        }

        $this->docs[] = "#### " . $this->formattedClassName($class) . " Constants";

        $this->docs[] = "";

        foreach ($constants as $constant) {
            $annotations = $this->getAnnotations($constant);
            preg_match("/@var (.*)/", $annotations, $matches);

            $type = $this->stripNamespace(
                $class->getNamespaceName(),
                $matches[1] ?? $constant->getType(),
            );

            $name = $constant->getName();
            $value = var_export($constant->getValue(), true);
            $this->docs[] = "- ```php";
            $this->docs[] = "  public const {$type} {$name} = {$value};";
            $this->docs[] = "  ```";
            $this->addNarrative($constant, "    ", "- ");
            $this->docs[] = "";
        }
    }

    /**
     * @param ReflectionClass<object> $class
     */
    protected function addProperties(ReflectionClass $class) : void
    {
        $properties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $i => $property) {
            if ($property->getDeclaringClass() != $class) {
                unset($properties[$i]);
            }
        }

        if (! $properties) {
            return;
        }

        $this->docs[] = "#### " . $this->formattedClassName($class) . " Properties";

        $this->docs[] = "";

        foreach ($properties as $property) {
            $annotations = $this->getAnnotations($property);
            preg_match("/@var (.*)/", $annotations, $matches);

            $type = $this->stripNamespace(
                $class->getNamespaceName(),
                $matches[1] ?? $property->getType(),
            );

            $name = $property->getName();
            $end = ';';
            $hooks = array_keys($property->getHooks());

            if ($hooks) {
                $end = ' { ' . implode('; ', $hooks) . '; }';
            }

            $this->docs[] = "- ```php";
            $this->docs[] = "  public {$type} \${$name}{$end}";
            $this->docs[] = "  ```";
            $this->addNarrative($property, "    ", "- ");
            $this->docs[] = "";
        }
    }

    /**
     * @param ReflectionClass<object> $class
     */
    protected function addMethods(ReflectionClass $class) : void
    {
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $i => $method) {
            if ($method->getDeclaringClass() != $class) {
                unset($methods[$i]);
            }
        }

        if (! $methods) {
            return;
        }

        $this->docs[] = "#### " . $this->formattedClassName($class) . " Methods";

        $this->docs[] = "";

        // @TODO must show default values for params
        foreach ($methods as $method) {
            $annotations = $this->getAnnotations($method);
            $signature = "public function " . $method->getName() . "(";
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                preg_match("/@param (.*) \\\${$name}/m", $annotations, $matches);

                $type = $this->stripNamespace(
                    $class->getNamespaceName(),
                    $matches[1] ?? $parameter->getType(),
                );

                $signature .= "{$type} \${$name}"; // @TODO ADD DEFAULT VALUES IF PRESENT

                if ($parameter->isOptional()) {
                    if ($parameter->isDefaultValueConstant()) {
                        $default = (string) $parameter->getDefaultValueConstantName();

                        if (
                            ! str_contains($default, '::')
                            && str_contains($default, '\\')
                            && ! defined($default)
                        ) {
                            $short = substr(
                                $default,
                                (int) strrpos($default, '\\') + 1,
                            );

                            if (defined($short)) {
                                $default = $short;
                            }
                        }
                    } else {
                        $default = var_export($parameter->getDefaultValue(), true);

                        if ($default === 'NULL') {
                            $default = strtolower($default);
                        }

                        $emptyArray = var_export([], true);

                        if ($default === $emptyArray) {
                            $default = '[]';
                        }
                    }

                    $signature .= " = {$default}";
                }

                $signature .= ', ';
            }

            $signature = rtrim($signature, ", ");
            preg_match("/@return (.*)/", $annotations, $matches);

            $type = $this->stripNamespace(
                $class->getNamespaceName(),
                $matches[1] ?? $method->getReturnType(),
            ); // if null, blow up, need a return

            $signature .= ") : {$type};";

            if (strlen($signature) > 80) {
                $signature = $this->wrapSignature($signature);
            }

            $this->docs[] = "- ```php";
            $this->docs[] = "  {$signature}";
            $this->docs[] = "  ```";
            $this->addNarrative($method, "    ", "- ");
            $this->docs[] = "";
        }
    }

    /**
     * @param Reflectable $r
     */
    protected function cleanComment(
        ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionMethod $r,
    ) : string
    {
        $comment = $r->getDocComment();

        if ($comment === false || $comment === '') {
            return '';
        }

        $comment = (string) preg_replace("/^[ ]{0,}\/\*\*/m", "", $comment);
        $comment = (string) preg_replace("/^[ ]{0,}\*\//m", "", $comment);
        $comment = (string) preg_replace("/^[ ]{0,}\*[ ]{0,1}/m", "", $comment);
        return trim($comment);
    }

    /**
     * @param Reflectable $r
     */
    protected function getAnnotations(
        ReflectionClass|ReflectionClassConstant|ReflectionProperty|ReflectionMethod $r,
    ) : string
    {
        $comment = $this->cleanComment($r);
        $pos = strpos($comment, PHP_EOL . "@");

        if ($pos === false) {
            return "";
        }

        return substr($comment, $pos);
    }

    protected function stripNamespace(
        string $namespace,
        null|string|ReflectionType $type,
    ) : string
    {
        $typeString = $type instanceof ReflectionType
            ? $this->formatReflectionType($type)
            : (string) $type;

        return str_replace($namespace . "\\", "", $typeString);
    }

    protected function formatReflectionType(
        ReflectionType $type,
        bool $insideUnion = false,
    ) : string
    {
        if ($type instanceof ReflectionUnionType) {
            $parts = array_map(
                fn (ReflectionType $t) : string
                    => $this->formatReflectionType($t, true),
                $type->getTypes(),
            );

            $indexed = [];

            foreach ($parts as $i => $part) {
                $indexed[] = [$part, $i];
            }

            usort(
                $indexed,
                fn (array $a, array $b) : int
                    => $this->typePriority($a[0]) <=> $this->typePriority($b[0])
                        ?: $a[1] <=> $b[1],
            );

            return implode('|', array_column($indexed, 0));
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                fn (ReflectionType $t) : string => (string) $t,
                $type->getTypes(),
            );

            $out = implode('&', $parts);
            return $insideUnion ? "({$out})" : $out;
        }

        return (string) $type;
    }

    protected function typePriority(string $type) : int
    {
        /** @var list<string> $order */
        static $order = [
            'null',
            'bool',
            'true',
            'false',
            'int',
            'float',
            'string',
            'array',
            'object',
        ];

        $index = array_search(strtolower($type), $order, true);
        return $index === false ? PHP_INT_MAX : (int) $index;
    }

    protected function wrapSignature(string $signature) : string
    {
        [$params, $return] = explode(' : ', $signature);

        if (strpos($signature, '()') === false) {
            $params = str_replace("(", "(\n      ", $params);
            $params = str_replace(",", ",\n     ", $params);
            $params = str_replace(")", ",\n  )", $params);
        }

        return "{$params} : {$return}";
    }
}
