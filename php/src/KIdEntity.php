<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Convenience constants for the open entity catalog. */
final class KIdEntity
{
    public const MAINTAINER = 'm';
    public const ORGANIZATION = 'o';
    public const PROJECT = 'p';
    public const BRANCH = 'b';
    public const BUCKET = 'bucket';
    public const TABLE = 'table';
    public const COMPONENT = 'component';
    public const CONFIG = 'config';
    public const ROW = 'row';
    public const WORKSPACE = 'workspace';
    public const USER = 'user';
    public const APP = 'app';
    public const STREAM = 'stream';

    private function __construct()
    {
    }
}
