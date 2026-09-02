<?php

declare(strict_types=1);

namespace App;

/** Thrown by RuleFactory when the form doesn't build a valid rule. */
final class RuleValidationException extends \Exception
{
    /** @param array<string, string> $errors field key => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The rule has problems.');
    }
}
