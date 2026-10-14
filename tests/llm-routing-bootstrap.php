<?php

$root = dirname(__DIR__);
$loader = require $root.'/vendor/autoload.php';
$map = [];
foreach ($loader->getClassMap() as $class => $file) {
    if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Tests\\')) {
        $relative = str_starts_with($class, 'App\\') ? 'app/'.str_replace('\\', '/', substr($class, 4)).'.php' : 'tests/'.str_replace('\\', '/', substr($class, 6)).'.php';
        if (is_file($root.'/'.$relative)) {
            $map[$class] = $root.'/'.$relative;
        }
    }
}
$loader->addClassMap($map);
$loader->addPsr4('App\\', $root.'/app', true);
$loader->addPsr4('Tests\\', $root.'/tests', true);
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $root;
putenv('APP_BASE_PATH='.$root);
