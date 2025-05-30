<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAliasRequest;
use App\Http\Requests\StoreAliasRequest;
use App\Http\Requests\UpdateAliasRequest;
use App\Http\Resources\AliasResource;
use App\Models\Domain;
use App\Models\Username;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class AliasController extends Controller
{
    public function index(IndexAliasRequest $request)
    {
        $aliases = user()->aliases()->with('recipients')
            ->when($request->input('recipient'), function ($query, $id) {
                return $query->usesRecipientWithId($id, $id === user()->default_recipient_id);
            })
            ->when($request->input('domain'), function ($query, $id) {
                return $query->belongsToAliasable('App\Models\Domain', $id);
            })
            ->when($request->input('username'), function ($query, $id) {
                return $query->belongsToAliasable('App\Models\Username', $id);
            })
            ->when($request->input('sort'), function ($query, $sort) {
                $direction = strpos($sort, '-') === 0 ? 'desc' : 'asc';
                $sort = ltrim($sort, '-');
                $compareOperator = $direction === 'desc' ? '>' : '<';

                // If sort is last_used then order by all and return
                if ($sort === 'last_used') {
                    return $query
                        ->orderByRaw(
                            "CASE
                            WHEN (last_forwarded {$compareOperator} last_replied
                            OR (last_forwarded IS NOT NULL
                            AND last_replied IS NULL))
                            AND (last_forwarded {$compareOperator} last_sent
                            OR (last_forwarded IS NOT NULL
                            AND last_sent IS NULL))
                                THEN last_forwarded
                            WHEN last_replied {$compareOperator} last_sent
                            OR (last_replied IS NOT NULL
                            AND last_sent IS NULL)
                                THEN last_replied
                            ELSE last_sent
                        END {$direction}"
                        )->orderBy('created_at', 'desc');
                }

                // If sort is created at then simply return as no need for secondary sorting below
                if ($sort === 'created_at') {
                    return $query->orderBy($sort, $direction);
                }

                // Secondary order by latest first
                return $query
                    ->orderBy($sort, $direction)
                    ->orderBy('created_at', 'desc');
            }, function ($query) {
                return $query->latest();
            })
            ->when($request->input('filter.active'), function ($query, $value) {
                $active = $value === 'true' ? true : false;

                return $query->where('active', $active);
            });

        // Keep /aliases?deleted=with for backwards compatibility
        if ($request->deleted === 'with' || $request->input('filter.deleted') === 'with') {
            $aliases->withTrashed();
        }

        if ($request->deleted === 'only' || $request->input('filter.deleted') === 'only') {
            $aliases->onlyTrashed();
        }

        if ($request->input('filter.search')) {
            $searchTerm = strtolower($request->input('filter.search'));

            $aliases = $aliases->get()->filter(function ($alias) use ($searchTerm) {
                return Str::contains(strtolower($alias->email), $searchTerm) || Str::contains(strtolower($alias->description), $searchTerm);
            })->values();
        }

        $aliases = $aliases->jsonPaginate($request->input('page.size') ?? 100);

        return AliasResource::collection($aliases);
    }

    public function show($id)
    {
        $alias = user()->aliases()->withTrashed()->findOrFail($id);

        return new AliasResource($alias->load('recipients'));
    }

    public function store(StoreAliasRequest $request)
    {
        if (user()->hasExceededNewAliasLimit()) {
            return response('You have reached your hourly limit for creating new aliases', 429);
        }

        if (isset($request->validated()['local_part_without_extension'])) {
            $localPart = $request->local_part; // To get the local_part with any potential extension

            // Local part has extension
            if (Str::contains($localPart, '+')) {
                $extension = Str::after($localPart, '+');
                $localPart = Str::before($localPart, '+');
            }

            $data = [
                'email' => $localPart.'@'.$request->domain,
                'local_part' => $localPart,
                'extension' => $extension ?? null,
            ];
        } else {
            $format = $request->input('format');
            // If the request doesn't have format, use user's default alias format
            if (! $format) {
                $format = user()->default_alias_format ?? 'random_characters';
            }

            $data = [];

            if ($format === 'random_words') {
                // Random Words
                $localPart = user()->generateRandomWordLocalPart();
            } elseif ($format === 'uuid') {
                // UUID
                $localPart = Uuid::uuid4();
                $data['id'] = $localPart;
            } else {
                // Random Characters
                $localPart = user()->generateRandomCharacterLocalPart(8);
            }

            $data['email'] = $localPart.'@'.$request->domain;
            $data['local_part'] = $localPart;
        }

        // Check if domain is for username or custom domain
        $parentDomain = collect(config('anonaddy.all_domains'))
            ->filter(function ($name) use ($request) {
                return Str::endsWith($request->domain, $name);
            })
            ->first();

        $aliasable = null;

        // This is an addy.io domain.
        if ($parentDomain) {
            $subdomain = substr($request->domain, 0, strrpos($request->domain, '.'.$parentDomain));

            if ($username = Username::where('username', $subdomain)->first()) {
                $aliasable = $username;
            }
        } else {
            if ($customDomain = Domain::where('domain', $request->domain)->first()) {
                $aliasable = $customDomain;
            }
        }

        $data['aliasable_id'] = $aliasable->id ?? null;
        $data['aliasable_type'] = $aliasable ? 'App\\Models\\'.class_basename($aliasable) : null;

        $data['domain'] = $request->domain;
        $data['description'] = $request->description;

        $alias = user()->aliases()->create($data);

        if ($request->recipient_ids) {
            $alias->recipients()->sync($request->recipient_ids);
        }

        return new AliasResource($alias->refresh()->load('recipients'));
    }

    public function update(UpdateAliasRequest $request, $id)
    {
        $alias = user()->aliases()->withTrashed()->findOrFail($id);

        if ($request->has('description')) {
            $alias->description = $request->description;
        }

        if ($request->has('from_name')) {
            $alias->from_name = $request->from_name;
        }

        $alias->save();

        return new AliasResource($alias->refresh()->load('recipients'));
    }

    public function restore($id)
    {
        $alias = user()->aliases()->withTrashed()->findOrFail($id);

        $alias->restore();

        return new AliasResource($alias->refresh()->load('recipients'));
    }

    public function destroy($id)
    {
        $alias = user()->aliases()->findOrFail($id);

        $alias->detachAllRecipients();

        $alias->delete();

        return response('', 204);
    }

    public function forget($id)
    {
        $alias = user()->aliases()->withTrashed()->findOrFail($id);

        $alias->detachAllRecipients();

        if ($alias->hasSharedDomain()) {
            // Remove all data from the alias and change user_id
            $alias->update([
                'user_id' => '00000000-0000-0000-0000-000000000000',
                'extension' => null,
                'description' => null,
                'emails_forwarded' => 0,
                'emails_blocked' => 0,
                'emails_replied' => 0,
                'emails_sent' => 0,
                'last_forwarded' => null,
                'last_blocked' => null,
                'last_replied' => null,
                'last_sent' => null,
                'active' => false,
                'deleted_at' => now(), // Soft delete to prevent from being regenerated
            ]);
        } else {
            $alias->forceDelete();
        }

        return response('', 204);
    }
}
