<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'email',
        'first_name',
        'last_name',
        'phone',
        'company_name',
        'job_title',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'tags',
        'source',
        'notes',
        'status',
        'sms_consent',
        'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'sms_consent' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->email;
    }

    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->state,
            $this->zip,
            $this->country,
        ]);

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByTag($query, $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function scopeByTags($query, array $tags)
    {
        return $query->where(function ($q) use ($tags) {
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        });
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('email', 'like', "%{$search}%")
              ->orWhere('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('company_name', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    public function hasTag(string $tag): bool
    {
        return is_array($this->tags) && in_array($tag, $this->tags);
    }

    public function addTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->update(['tags' => $tags]);
        }
    }

    public function removeTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        $tags = array_filter($tags, fn($t) => $t !== $tag);
        $this->update(['tags' => array_values($tags)]);
    }

    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    public function deactivate(): void
    {
        $this->update(['status' => 'inactive']);
    }

    public function getEmailVariables(): array
    {
        return [
            'contact_email' => $this->email,
            'contact_name' => $this->full_name,
            'contact_first_name' => $this->first_name ?? '',
            'contact_last_name' => $this->last_name ?? '',
            'contact_phone' => $this->phone ?? '',
            'contact_company' => $this->company_name ?? '',
            'contact_job_title' => $this->job_title ?? '',
            'contact_address' => $this->full_address ?? '',
            'recipient_email' => $this->email,
            'recipient_name' => $this->full_name,
            'recipient_first_name' => $this->first_name ?? '',
            'recipient_last_name' => $this->last_name ?? '',
        ];
    }

    public static function createOrUpdateFromSource(
        int $companyId,
        array $data,
        ?string $source = null,
        array $tags = [],
        ?int $locationId = null,
        ?int $createdBy = null
    ): self {
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('Email is required to create or update a contact');
        }

        $contact = self::where('company_id', $companyId)
            ->where('email', $data['email'])
            ->first();

        if ($contact) {
            $updateData = [];

            if (!empty($data['first_name']) && empty($contact->first_name)) {
                $updateData['first_name'] = $data['first_name'];
            }
            if (!empty($data['last_name']) && empty($contact->last_name)) {
                $updateData['last_name'] = $data['last_name'];
            }
            if (!empty($data['phone']) && empty($contact->phone)) {
                $updateData['phone'] = $data['phone'];
            }
            if (!empty($data['address']) && empty($contact->address)) {
                $updateData['address'] = $data['address'];
            }
            if (!empty($data['city']) && empty($contact->city)) {
                $updateData['city'] = $data['city'];
            }
            if (!empty($data['state']) && empty($contact->state)) {
                $updateData['state'] = $data['state'];
            }
            if (!empty($data['zip']) && empty($contact->zip)) {
                $updateData['zip'] = $data['zip'];
            }
            if (!empty($data['country']) && empty($contact->country)) {
                $updateData['country'] = $data['country'];
            }

            if (isset($data['sms_consent'])) {
                $updateData['sms_consent'] = (bool) $data['sms_consent'];
            }

            if ($locationId && !$contact->location_id) {
                $updateData['location_id'] = $locationId;
            }

            if (!empty($updateData)) {
                $contact->update($updateData);
            }

            foreach ($tags as $tag) {
                $contact->addTag($tag);
            }

            return $contact;
        }

        $firstName = $data['first_name'] ?? null;
        $lastName = $data['last_name'] ?? null;

        if (empty($firstName) && !empty($data['name'])) {
            $nameParts = explode(' ', trim($data['name']), 2);
            $firstName = $nameParts[0] ?? null;
            $lastName = $nameParts[1] ?? null;
        }

        $contact = self::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'email' => $data['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'country' => $data['country'] ?? null,
            'sms_consent' => (bool) ($data['sms_consent'] ?? false),
            'tags' => !empty($tags) ? $tags : null,
            'source' => $source,
            'status' => 'active',
            'created_by' => $createdBy,
        ]);

        return $contact;
    }

    public function addTags(array $newTags): void
    {
        $currentTags = $this->tags ?? [];
        $mergedTags = array_unique(array_merge($currentTags, $newTags));
        $this->update(['tags' => array_values($mergedTags)]);
    }

    public function removeTags(array $tagsToRemove): void
    {
        $currentTags = $this->tags ?? [];
        $filteredTags = array_filter($currentTags, fn($t) => !in_array($t, $tagsToRemove));
        $this->update(['tags' => !empty($filteredTags) ? array_values($filteredTags) : null]);
    }

    public function setTags(array $tags): void
    {
        $this->update(['tags' => !empty($tags) ? array_values(array_unique($tags)) : null]);
    }
}
