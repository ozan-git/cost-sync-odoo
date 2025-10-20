<?php

namespace App\Services\Odoo;

class OdooResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly array $payload,
        public readonly array $response = [],
        public readonly ?string $message = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->ok;
    }
}
