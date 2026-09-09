<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): Response
    {
        return $project->user_id === $user->id
            || $project->memberships()->where('user_id', $user->id)->exists()
                ? Response::allow()
                : Response::denyAsNotFound();
    }

    public function update(User $user, Project $project): Response
    {
        if ($project->user_id === $user->id) {
            return Response::allow();
        }

        return $this->view($user, $project)->allowed()
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    /**
     * The MVP delete endpoint archives the project without removing its data.
     */
    public function delete(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    public function viewMembers(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }

    public function manageMembers(User $user, Project $project): Response
    {
        $access = $this->update($user, $project);

        if ($access->denied()) {
            return $access;
        }

        return $project->status === ProjectStatus::Archived
            ? Response::denyWithStatus(409, 'Archived projects allow collaborator revocation only.')
            : Response::allow();
    }

    public function revokeMembers(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }
}
