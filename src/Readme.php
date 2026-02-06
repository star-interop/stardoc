<?php
declare(strict_types=1);

namespace StarInterop\Stardoc;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

class Readme
{
    protected array $list = [];

    protected array $docs = [];

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
            $class = new ReflectionClass($this->namespace . $interface);
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
        $readme = str_replace('{{= list }}', $list, $readme);
        $readme = str_replace('{{= docs }}', $docs, $readme);
        $readme = preg_replace('/^\s+$/m', '', $readme);
        $readme = preg_replace('/^- Methods:\n\n###/m', "###", $readme);
        return $readme;
    }

    protected function formattedClassName(ReflectionClass $class) : string
    {
        return "_"
            . $this->stripNamespace(
                $class->getNamespaceName(),
                $class->getName()
            )
            . "_";
    }

    protected function addInterface(ReflectionClass $class) : void
    {
        $this->addList($class);
        $subtitle = "### " . $this->formattedClassName($class);
        $this->docs[] = $subtitle;
        $this->docs[] = "";
        $this->addNarrative($class, '', '');
    }

    protected function addList($r)
    {
        $comment = $this->cleanComment($r);
        $blankLine = strpos($comment, PHP_EOL . PHP_EOL);

        $item = $blankLine === false
            ? $comment
            : substr($comment, 0, $blankLine);

        $this->list[] = '- ' . str_replace(PHP_EOL, ' ', $item);
    }

    protected function addNarrative($r, $indent, $prefix)
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

    protected function addConstants(ReflectionClass $class) : void
    {
        $constants = $class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC);

        if (! $constants) {
            return;
        }

        $this->docs[] = "#### "
            . $this->formattedClassName($class)
            . " Constants";

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

    protected function addProperties(ReflectionClass $class) : void
    {
        $properties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

        if (! $properties) {
            return;
        }

        $this->docs[] = "#### "
            . $this->formattedClassName($class)
            . " Properties";

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

        $this->docs[] = "#### "
            . $this->formattedClassName($class)
            . " Methods";

        $this->docs[] = "";

        // @TODO must show default values for params
        foreach ($methods as $method) {

            $annotations = $this->getAnnotations($method);
            $signature = "public function " . $method->getName() . "(";
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                preg_match("/@param (.*) \\\${$name}/m", $annotations, $matches);
                $type = $this->stripNamespace($class->getNamespaceName(), $matches[1] ?? $parameter->getType());
                $signature .= "{$type} \${$name}"; // @TODO ADD DEFAULT VALUES IF PRESENT

                if ($parameter->isOptional()) {
                    $default = var_export(
                        $parameter->getDefaultValue(),
                        true
                    );

                    if ($default === 'NULL') {
                        $default = strtolower($default);
                    }

                    $emptyArray = var_export([], true);

                    if ($default === $emptyArray) {
                        $default = '[]';
                    }

                    $signature .= " = {$default}";
                }

                $signature .= ', ';
            }

            $signature = rtrim($signature, ", ");
            preg_match("/@return (.*)/", $annotations, $matches);
            $type = $this->stripNamespace($class->getNamespaceName(), $matches[1] ?? $method->getReturnType()); // if null, blow up, need a return
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

    protected function cleanComment($r)
    {
        $comment = $r->getDocComment();

        if (! $comment) {
            return '';
        }

        $comment = preg_replace("/^[ ]{0,}\/\*\*/m", "", $comment);
        $comment = preg_replace("/^[ ]{0,}\*\//m", "", $comment);
        $comment = preg_replace("/^[ ]{0,}\*[ ]{0,1}/m", "", $comment);
        return trim($comment);
    }

    protected function getAnnotations($r)
    {
        $comment = $this->cleanComment($r);
        $pos = strpos($comment, PHP_EOL . "@");

        if ($pos === false) {
            return "";
        }

        return substr($comment, $pos);
    }

    protected function stripNamespace($namespace, $type) : string
    {
        $namespace = str_replace(
            $namespace . "\\",
            "",
            (string) $type
        );

        return $namespace;
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
