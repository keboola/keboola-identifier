<?php

declare(strict_types=1);

use Keboola\Identifier\KIdEntity;
use Keboola\Identifier\KIdValidator;

require __DIR__ . '/../vendor/autoload.php';

$kid = KIdValidator::create('kbc-eu-central-1', [KIdEntity::CONFIG => 'my config', KIdEntity::PROJECT => '9876']);
echo $kid, PHP_EOL;
echo $kid->project(), PHP_EOL;
echo $kid->get(KIdEntity::CONFIG), PHP_EOL;
echo KIdValidator::validate($kid->toString()) ? 'valid' : 'invalid', PHP_EOL;
