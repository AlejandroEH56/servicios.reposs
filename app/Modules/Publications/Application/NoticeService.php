<?php

namespace App\Modules\Publications\Application;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Publications\Application\DTO\NoticeInput;
use App\Modules\Publications\Application\Ports\NoticeRepository;

final class NoticeService
{
    public function __construct(private NoticeRepository $notices, private AuthorizationDirectory $authorization) {}

    /** @param array<string, mixed> $query
     * @return array<string, mixed> */
    public function page(bool $public, array $query, ?string $actor): array
    {
        if (! $public) {
            $this->authorize($actor);
        }

        return $this->notices->page($public, $query);
    }

    /** @return array<string, mixed> */
    public function get(string $id, bool $public, ?string $actor): array
    {
        if (! $public) {
            $this->authorize($actor);
        }

        return $this->notices->get($id, $public);
    }

    /** @return array<string, mixed> */
    public function command(string $action, ?string $id, ?NoticeInput $input, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        $this->authorize($actor);

        return $this->notices->command($action, $id, $input, $version, $key, $actor, $correlation);
    }

    public function authorize(?string $actor): void
    {
        abort_unless($actor !== null && $this->authorization->allows($actor, 'publicaciones.administrar'), 403);
    }
}
