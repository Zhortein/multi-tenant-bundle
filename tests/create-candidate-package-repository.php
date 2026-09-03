<?php

declare(strict_types=1);

$archive = $argv[1] ?? null;
$commit = $argv[2] ?? null;

if (!is_string($archive) || !is_file($archive) || !is_string($commit) || 1 !== preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, 'Usage: create-candidate-package-repository.php <archive.zip> <40-character-commit>'.PHP_EOL);
    exit(2);
}

$manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$copyKeys = [
    'name',
    'type',
    'description',
    'keywords',
    'homepage',
    'license',
    'support',
    'authors',
    'require',
    'conflict',
    'provide',
    'replace',
    'suggest',
    'autoload',
    'extra',
    'include-path',
    'target-dir',
    'bin',
];
$package = ['version' => 'dev-candidate'];

foreach ($copyKeys as $key) {
    if (array_key_exists($key, $manifest)) {
        $package[$key] = $manifest[$key];
    }
}

$package['dist'] = [
    'type' => 'zip',
    'url' => 'file://'.realpath($archive),
    'reference' => $commit,
];

fwrite(STDOUT, json_encode([
    'type' => 'package',
    'canonical' => true,
    'package' => $package,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
