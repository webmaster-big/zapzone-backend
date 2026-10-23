<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\MessageLink;
use App\Models\MessageLinkUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageLinkController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        if (!MessageLink::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->resolveAuthUser($request);
        $locationIds = $this->staffLocationIds($request);
        $locations = Location::query()
            ->where('company_id', $user?->company_id)
            ->whereIn('id', $locationIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $links = MessageLink::query()
            ->with(['urls' => fn ($query) => $query->whereIn('location_id', $locationIds)])
            ->where('company_id', $user?->company_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'links' => $links->map(fn (MessageLink $link) => $this->present($link))->values(),
                'locations' => $locations->map(fn (Location $location) => ['id' => (int) $location->id, 'name' => $location->name])->values(),
                'can_manage_links' => $this->isEffectiveAdmin($request),
                'max_links' => MessageLink::MAX_LINKS,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!MessageLink::isAvailable()) {
            return $this->unavailable();
        }

        if (!$this->isEffectiveAdmin($request)) {
            return $this->adminsOnly();
        }

        $this->normaliseUrls($request, ['default_url']);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'default_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
        ], [
            'default_url.url' => 'Enter a full web address, for example https://instagram.com/zapzone.',
        ]);

        if (!$this->hasDottedHost($validated['default_url'] ?? null)) {
            return $this->notAnAddress('default_url');
        }

        $user = $this->resolveAuthUser($request);
        $key = MessageLink::keyFor($validated['label']);

        if (MessageLink::where('company_id', $user->company_id)->count() >= MessageLink::MAX_LINKS) {
            return $this->invalid('label', sprintf('You can keep up to %d links. Remove one you no longer use first.', MessageLink::MAX_LINKS));
        }

        if (MessageLink::where('company_id', $user->company_id)->where('key', $key)->exists()) {
            return $this->invalid('label', sprintf('A link named {{link_%s}} already exists. Give this one a different name.', $key));
        }

        $link = MessageLink::create([
            'company_id' => $user->company_id,
            'key' => $key,
            'label' => trim($validated['label']),
            'default_url' => $this->cleanUrl($validated['default_url'] ?? null),
            'sort_order' => (int) MessageLink::where('company_id', $user->company_id)->max('sort_order') + 1,
        ]);

        ActivityLog::log('message_link_created', 'photos', sprintf('Added the message link "%s"', $link->label), $user->id, $user->location_id, 'message_link', $link->id);

        return response()->json(['success' => true, 'data' => $this->present($link->load('urls'))], 201);
    }

    public function update(Request $request, MessageLink $messageLink): JsonResponse
    {
        if (!$this->isEffectiveAdmin($request)) {
            return $this->adminsOnly();
        }

        if ($denied = $this->otherCompany($request, $messageLink)) {
            return $denied;
        }

        $this->normaliseUrls($request, ['default_url']);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:80'],
            'default_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:http,https'],
        ], [
            'default_url.url' => 'Enter a full web address, for example https://instagram.com/zapzone.',
        ]);

        if (!$this->hasDottedHost($validated['default_url'] ?? null)) {
            return $this->notAnAddress('default_url');
        }

        $changes = [];
        if (array_key_exists('label', $validated)) {
            $changes['label'] = trim($validated['label']);
        }
        if (array_key_exists('default_url', $validated)) {
            $changes['default_url'] = $this->cleanUrl($validated['default_url']);
        }

        $messageLink->update($changes);

        ActivityLog::log('message_link_updated', 'photos', sprintf('Updated the message link "%s"', $messageLink->label), $this->resolveAuthUser($request)?->id, null, 'message_link', $messageLink->id, $changes);

        return response()->json(['success' => true, 'data' => $this->present($this->scopedUrls($request, $messageLink))]);
    }

    public function setUrl(Request $request, MessageLink $messageLink): JsonResponse
    {
        if ($denied = $this->otherCompany($request, $messageLink)) {
            return $denied;
        }

        $this->normaliseUrls($request, ['url']);

        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
            'url' => ['nullable', 'string', 'max:500', 'url:http,https'],
        ], [
            'url.url' => 'Enter a full web address, for example https://instagram.com/zapzone.',
        ]);

        if (!$this->hasDottedHost($validated['url'] ?? null)) {
            return $this->notAnAddress('url');
        }

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        if (!in_array((int) $location->id, $this->staffLocationIds($request), true)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only change links for your own location.',
            ], 403);
        }

        $url = $this->cleanUrl($validated['url'] ?? null);

        DB::transaction(function () use ($messageLink, $location, $url) {
            if ($url === null) {
                MessageLinkUrl::where('message_link_id', $messageLink->id)->where('location_id', $location->id)->delete();

                return;
            }

            MessageLinkUrl::updateOrCreate(
                ['message_link_id' => $messageLink->id, 'location_id' => $location->id],
                ['url' => $url]
            );
        });

        ActivityLog::log(
            'message_link_url_updated',
            'photos',
            sprintf('%s the %s link for %s', $url === null ? 'Cleared' : 'Set', $messageLink->label, $location->name),
            $this->resolveAuthUser($request)?->id,
            $location->id,
            'message_link',
            $messageLink->id
        );

        return response()->json(['success' => true, 'data' => $this->present($this->scopedUrls($request, $messageLink))]);
    }

    public function destroy(Request $request, MessageLink $messageLink): JsonResponse
    {
        if (!$this->isEffectiveAdmin($request)) {
            return $this->adminsOnly();
        }

        if ($denied = $this->otherCompany($request, $messageLink)) {
            return $denied;
        }

        $label = $messageLink->label;
        $messageLink->delete();

        ActivityLog::log('message_link_deleted', 'photos', sprintf('Removed the message link "%s"', $label), $this->resolveAuthUser($request)?->id, null, 'message_link', null);

        return response()->json(['success' => true, 'message' => 'Link removed. Messages that still mention it show nothing in its place.']);
    }

    protected function present(MessageLink $link): array
    {
        return [
            'id' => (int) $link->id,
            'key' => $link->key,
            'variable' => 'link_' . $link->key,
            'label' => $link->label,
            'default_url' => $link->default_url,
            'urls' => $link->urls
                ->map(fn (MessageLinkUrl $url) => ['location_id' => (int) $url->location_id, 'url' => $url->url])
                ->values()
                ->all(),
        ];
    }

    protected function scopedUrls(Request $request, MessageLink $link): MessageLink
    {
        $locationIds = $this->staffLocationIds($request);

        return $link->load(['urls' => fn ($query) => $query->whereIn('location_id', $locationIds)]);
    }

    protected function normaliseUrls(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (!$request->has($field) || !is_string($request->input($field))) {
                continue;
            }

            $value = trim($request->input($field));
            $bare = ltrim($value, '/');

            if (preg_match('#^[^\s/?\#@:]+\.[^\s/?\#@:]+(?:[:/?\#]|$)#', $bare)) {
                $value = 'https://' . $bare;
            }

            $request->merge([$field => $value]);
        }
    }

    protected function hasDottedHost(?string $url): bool
    {
        return $url === null || $url === '' || str_contains((string) parse_url($url, PHP_URL_HOST), '.');
    }

    protected function notAnAddress(string $field): JsonResponse
    {
        return $this->invalid($field, 'Enter a full web address, for example https://instagram.com/zapzone.');
    }

    protected function cleanUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' ? null : $url;
    }

    protected function otherCompany(Request $request, MessageLink $link): ?JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        if ($user === null || (int) $link->company_id !== (int) $user->company_id) {
            return response()->json(['success' => false, 'message' => 'That link could not be found.'], 404);
        }

        return null;
    }

    protected function isEffectiveAdmin(Request $request): bool
    {
        $user = $this->resolveAuthUser($request);

        return $user !== null && in_array(EnsureStaff::effectiveRole($user), ['company_admin', 'admin'], true);
    }

    protected function adminsOnly(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only company admins can add, rename or remove links. You can still set your location\'s web address for each link.',
        ], 403);
    }

    protected function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    protected function unavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'MODULE_UNAVAILABLE',
            'message' => 'Message links are not set up yet.',
        ], 503);
    }
}
