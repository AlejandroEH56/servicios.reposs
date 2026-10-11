<?php

namespace Tests\Unit;

use App\Modules\Shared\Infrastructure\Operations\EnvironmentProfileSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentProfileSourceTest extends TestCase
{
    public function test_regeneration_uses_the_active_institutional_credential_without_copying_its_database_principal(): void
    {
        $active = "MICROSOFT_CLIENT_SECRET=active-fixture\nMICROSOFT_TENANT_ID=tenant-fixture\nMICROSOFT_REDIRECT_URI=https://localhost:8443/callback\nDB_PASSWORD=admin-fixture\nAPP_KEY=active-key\n";
        $updates = (new EnvironmentProfileSource)->updates($active, "MICROSOFT_CLIENT_SECRET=retired-fixture\nAPP_KEY=stage-key\nAPP_PREVIOUS_KEYS=previous-key\n");
        $this->assertSame('active-fixture', $updates['MICROSOFT_CLIENT_SECRET']);
        $this->assertSame('tenant-fixture', $updates['MICROSOFT_TENANT_ID']);
        $this->assertSame('stage-key', $updates['APP_KEY']);
        $this->assertSame('previous-key', $updates['APP_PREVIOUS_KEYS']);
        $this->assertArrayNotHasKey('DB_PASSWORD', $updates);
        $this->assertArrayNotHasKey('MICROSOFT_REDIRECT_URI', $updates);
    }

    public function test_missing_active_credential_never_falls_back_to_a_retired_profile(): void
    {
        $this->expectException(RuntimeException::class);
        (new EnvironmentProfileSource)->updates('APP_KEY=fixture', 'MICROSOFT_CLIENT_SECRET=retired-fixture');
    }

    public function test_explicitly_empty_credential_disables_it_instead_of_restoring_a_retired_value(): void
    {
        $updates = (new EnvironmentProfileSource)->updates('MICROSOFT_CLIENT_SECRET=', 'MICROSOFT_CLIENT_SECRET=retired-fixture');
        $this->assertSame('', $updates['MICROSOFT_CLIENT_SECRET']);
    }
}
