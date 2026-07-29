<?php

namespace HiEvents\Services\Domain\Wallet\DTO;

class AppleWalletPassResponseDTO
{
    public function __construct(
        public readonly string $contents,
        public readonly string $filename,
    ) {}
}
