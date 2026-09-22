<?php

declare(strict_types=1);

use Keboola\Identifier\Entity;
use Keboola\Identifier\Kid;

require __DIR__ . '/../vendor/autoload.php';

$kid = Kid::create('kbc-eu-central-1', [Entity::CONFIG => 'my config', Entity::PROJECT => '9876']);
echo $kid, PHP_EOL;
echo $kid->project(), PHP_EOL;
echo $kid->get(Entity::CONFIG), PHP_EOL;
