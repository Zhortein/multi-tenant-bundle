<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__."/src",
        __DIR__."/tests",
    ])
    ->exclude([
        "ConsumerApp/var",
        "ConsumerApp/vendor",
    ])
;

return (new PhpCsFixer\Config())
    ->setRules(["@Symfony" => true])
    ->setFinder($finder)
;
