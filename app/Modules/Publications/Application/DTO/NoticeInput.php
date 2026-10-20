<?php

namespace App\Modules\Publications\Application\DTO;

use App\Modules\Publications\Domain\Services\NoticeContent;
use InvalidArgumentException;

final readonly class NoticeInput
{
    public string $type;

    public string $title;

    public string $description;

    public string $requirements;

    public ?string $url;

    public ?string $startsAt;

    public ?string $endsAt;

    public function __construct(string $type, string $title, string $description, string $requirements, ?string $url,
        ?string $startsAt, ?string $endsAt, public ?string $imageId)
    {
        if (! in_array($type, ['RESIDENCIAS', 'SERVICIO_SOCIAL', 'EMPLEO'], true)) {
            throw new InvalidArgumentException('NOTICE_TYPE_INVALID');
        }
        $this->type = $type === 'RESIDENCIAS' ? 'RESIDENCIA' : $type;
        $this->title = NoticeContent::text($title, 200, true);
        $this->description = NoticeContent::text($description, 12000, true);
        $this->requirements = NoticeContent::text($requirements, 6000);
        $this->url = NoticeContent::url($url);
        $this->startsAt = NoticeContent::instant($startsAt);
        $this->endsAt = NoticeContent::instant($endsAt);
        NoticeContent::window($this->startsAt, $this->endsAt);
    }
}
