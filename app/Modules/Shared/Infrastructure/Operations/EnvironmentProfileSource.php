<?php

namespace App\Modules\Shared\Infrastructure\Operations;

use Dotenv\Dotenv;
use RuntimeException;

class EnvironmentProfileSource
{
    /** @return array<string, string> */
    public function updates(string $activeProfile, ?string $existingProfile = null): array
    {
        $active = Dotenv::parse($activeProfile);
        if (! array_key_exists('MICROSOFT_CLIENT_SECRET', $active)) {
            throw new RuntimeException('Active institutional credential source is required.');
        }
        $updates = [];
        foreach ($active as $key => $value) {
            if (str_starts_with($key, 'MICROSOFT_') && $key !== 'MICROSOFT_REDIRECT_URI') {
                $updates[$key] = (string) $value;
            }
        }
        if ($existingProfile !== null) {
            $existing = Dotenv::parse($existingProfile);
            foreach (['APP_KEY', 'APP_PREVIOUS_KEYS'] as $key) {
                if (array_key_exists($key, $existing)) {
                    $updates[$key] = (string) $existing[$key];
                }
            }
        }

        return $updates;
    }
}
