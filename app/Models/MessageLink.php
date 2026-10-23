<?php

namespace App\Models;

use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageLink extends Model
{
    public const MAX_LINKS = 30;

    protected $fillable = [
        'company_id',
        'key',
        'label',
        'default_url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function urls(): HasMany
    {
        return $this->hasMany(MessageLinkUrl::class);
    }

    public static function isAvailable(): bool
    {
        return SchemaSupport::hasTable('message_links') && SchemaSupport::hasTable('message_link_urls');
    }

    public static function keyFor(string $label): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($label))), '_');

        return mb_substr($key === '' ? 'link' : $key, 0, 40);
    }

    public function urlFor(?Location $location): ?string
    {
        $specific = $location
            ? ($this->relationLoaded('urls')
                ? $this->urls->firstWhere('location_id', $location->id)
                : $this->urls()->where('location_id', $location->id)->first())
            : null;

        $url = trim((string) ($specific?->url ?? $this->default_url ?? ''));

        return $url === '' ? null : $url;
    }

    public static function resolvedFor(?int $companyId, ?Location $location): Collection
    {
        if ($companyId === null || !self::isAvailable()) {
            return collect();
        }

        return self::query()
            ->with('urls')
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MessageLink $link) => [
                'key' => $link->key,
                'label' => $link->label,
                'url' => $link->urlFor($location),
            ])
            ->values();
    }
}
