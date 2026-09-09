<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $role = Arr::pull($validated, 'role', UserRole::User->value);

        $user = DB::transaction(function () use ($validated, $role): User {
            $user = User::create($validated);
            $user->assignRole($role);

            return $user->load('roles');
        });

        return UserResource::make($user)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->loadMissing('roles'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();
        $role = Arr::pull($validated, 'role');

        DB::transaction(function () use ($user, $validated, $role): void {
            $user->update($validated);

            if ($role !== null) {
                $user->syncRoles($role);
            }
        });

        return UserResource::make($user->load('roles'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): Response
    {
        Gate::authorize('delete', $user);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
