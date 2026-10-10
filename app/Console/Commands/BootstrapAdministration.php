<?php

namespace App\Console\Commands;

use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\Shared\Infrastructure\Operations\OperationalAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[Signature('iam:bootstrap-administration {identity}')]
#[Description('Assign the first IAM and Organization administrator from the restricted operator environment')]
class BootstrapAdministration extends Command
{
    public function handle(OperationalAccess $access, AuthorizationAdministration $administration): int
    {
        $owner = $access->owner();
        if (! Str::isUlid((string) $this->argument('identity'))) {
            $this->error('Invalid identity identifier.');

            return self::FAILURE;
        }
        try {
            $administration->bootstrap($this->argument('identity'), $owner);
        } catch (HttpExceptionInterface) {
            $this->error('ADMINISTRATION_BOOTSTRAP_REJECTED');

            return self::FAILURE;
        }
        $this->info('First administrator assigned and audited; previous sessions require fresh login.');

        return self::SUCCESS;
    }
}
