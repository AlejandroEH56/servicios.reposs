<?php

namespace App\Modules\IAM\Application;

final readonly class VerifiedIdentity
{
    public function __construct(
        public string $tenantId,
        public string $objectId,
        public string $name,
        public ?string $email,
        public ?string $principalName,
        public ?string $jobTitle = null,
        public bool $profileAvailable = false,
    ) {}
}
