<?php
$classLoader = require dirname(__DIR__) . '/vendor/autoload.php';
$configFile = $argv[1] ?? null;

if (! $configFile) {
    $configFile = getcwd() . '/config/README.config.php';
}

$config = require $configFile;
$readme = new \StarInterop\Stardoc\Readme($classLoader, ...$config);
echo $readme();
