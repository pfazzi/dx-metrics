#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Pfazzi\DxMetrics\Analyze;
use Symfony\Component\Console\Application;

$application = new Application();

$application->add(new Analyze());

$application->run();