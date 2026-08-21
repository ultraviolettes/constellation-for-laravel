<?php

namespace Ultraviolettes\Constellation\Exceptions;

use RuntimeException;

final class CollectorUnavailable extends RuntimeException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
