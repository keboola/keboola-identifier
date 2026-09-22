<?php

// Deliberately no strict_types: scalar argument coercion is controlled by the caller.
return static function (mixed $stack, array $entities): \Keboola\Identifier\KIdValidator {
    return \Keboola\Identifier\KIdValidator::create($stack, $entities);
};
