<?php
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(['src', 'tests'])
    ->exclude(['vendor']);

return (new PhpCsFixer\Config())
    ->setRules([
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setFinder($finder);