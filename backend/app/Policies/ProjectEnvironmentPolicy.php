<?php

namespace App\Policies;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectEnvironmentPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): Response
    {
        return $this->projects->update($user, $project);
    }

    public function view(User $user, ProjectEnvironment $environment): Response
    {
        return $this->viewAny($user, $environment->project);
    }

    public function create(User $user, Project $project): Response
    {
        $access = $this->viewAny($user, $project);

        if ($access->denied()) {
            return $access;
        }

        return $project->status === ProjectStatus::Archived
            ? Response::denyWithStatus(409, 'Archived projects are read-only. Reactivate the project first.')
            : Response::allow();
    }

    public function update(User $user, ProjectEnvironment $environment): Response
    {
        $access = $this->create($user, $environment->project);

        if ($access->denied()) {
            return $access;
        }

        return $environment->desired_state === EnvironmentDesiredState::Deleted
            || in_array($environment->status, [
                EnvironmentStatus::Provisioning,
                EnvironmentStatus::Starting,
                EnvironmentStatus::Stopping,
                EnvironmentStatus::Deleting,
            ], true)
                ? Response::denyWithStatus(409, 'Wait for the environment operation to finish before editing.')
                : Response::allow();
    }

    public function delete(User $user, ProjectEnvironment $environment): Response
    {
        $access = $this->create($user, $environment->project);

        if ($access->denied()) {
            return $access;
        }

        return $environment->isUnprovisioned()
            ? Response::allow()
            : Response::denyWithStatus(409, 'Provisioned environments require runtime cleanup before deletion.');
    }

    public function start(User $user, ProjectEnvironment $environment): Response
    {
        $access = $this->view($user, $environment);

        if ($access->denied()) {
            return $access;
        }

        if ($environment->project->status !== ProjectStatus::Active) {
            return Response::denyWithStatus(409, 'Activate the project before starting an environment.');
        }

        return $environment->desired_state === EnvironmentDesiredState::Deleted
            || in_array($environment->status, [EnvironmentStatus::Stopping, EnvironmentStatus::Deleting], true)
                ? Response::denyWithStatus(409, 'Wait for the environment operation to finish before starting.')
                : Response::allow();
    }

    public function stop(User $user, ProjectEnvironment $environment): Response
    {
        $access = $this->view($user, $environment);
        if ($access->denied()) {
            return $access;
        }

        return $environment->runtime_reference !== null
            && in_array($environment->status, [EnvironmentStatus::Running, EnvironmentStatus::Ready, EnvironmentStatus::Stopped, EnvironmentStatus::Stopping, EnvironmentStatus::Error], true)
                ? Response::allow()
                : Response::denyWithStatus(409, 'Wait for startup to finish before stopping the environment.');
    }

    public function shell(User $user, ProjectEnvironment $environment): Response
    {
        $access = $this->view($user, $environment);
        if ($access->denied()) {
            return $access;
        }

        return $environment->project->status === ProjectStatus::Active
            && $environment->desired_state === EnvironmentDesiredState::Running
            && in_array($environment->status, [EnvironmentStatus::Running, EnvironmentStatus::Ready], true)
            && $environment->runtime_reference !== null
                ? Response::allow()
                : Response::denyWithStatus(409, 'The environment must be running in an active project to open a shell.');
    }
}
