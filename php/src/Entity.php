<?php

declare(strict_types=1);

namespace Keboola\Identifier;

enum Entity: string
{
    case MAINTAINER = 'm';
    case ORGANIZATION = 'o';
    case PROJECT = 'p';
    case BRANCH = 'b';
    case BUCKET = 'bucket';
    case TABLE = 'table';
    case COMPONENT = 'component';
    case CONFIG = 'config';
    case ROW = 'row';
    case WORKSPACE = 'workspace';
    case USER = 'user';
    case APP = 'app';
    case STREAM = 'stream';
}
