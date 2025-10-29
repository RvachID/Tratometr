<?php

namespace app\services\Scan;

/**
 * DTO результата распознавания цены.
 */
final class RecognizeResult
{
    public bool $success;
    public ?float $amount;
    public ?string $parsedText;
    public ?string $pass;
    public ?string $error;
    public ?string $reason;
    public array $context;

    private function __construct(
        bool $success,
        ?float $amount,
        ?string $parsedText,
        ?string $pass,
        ?string $error,
        ?string $reason,
        array $context = []
    ) {
        $this->success = $success;
        $this->amount = $amount;
        $this->parsedText = $parsedText;
        $this->pass = $pass;
        $this->error = $error;
        $this->reason = $reason;
        $this->context = $context;
    }

    public static function success(float $amount, string $parsedText, string $pass, array $context = []): self
    {
        return new self(true, $amount, $parsedText, $pass, null, null, $context);
    }

    public static function failure(string $error, ?string $reason = null, array $context = []): self
    {
        return new self(false, null, null, null, $error, $reason, $context);
    }
}
