<?php

declare(strict_types=1);

use Keboola\Identifier\Kid;

require __DIR__ . '/../vendor/autoload.php';

$kid = Kid::create('kbc-eu-central-1', ['config' => 'my config', 'project' => '9876']);
echo $kid, PHP_EOL;
echo $kid->project(), PHP_EOL;
echo $kid->get('config'), PHP_EOL;
echo Kid::validate($kid->toString()) ? 'valid' : 'invalid', PHP_EOL;
