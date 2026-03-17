<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use RuntimeException;
use Throwable;

final class ImportProcessException extends RuntimeException
{
    private ?int $batchId;

    public function __construct(string $message, ?int $batchId = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->batchId = $batchId;
    }

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }
}
