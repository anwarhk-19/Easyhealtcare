<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

/**
 * Holds the active tenant (organisation), membership and branch scope for the current request or job.
 * Every tenant-scoped query depends on this; if it is empty, tenant queries fail closed.
 */
class TenantContext
{
    protected ?Organization $organization = null;

    protected ?Membership $membership = null;

    protected int $bypassDepth = 0;

    /** @var array<string>|null */
    protected ?array $permissionCache = null;

    public function set(Organization $organization, ?Membership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
        $this->permissionCache = null;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
        $this->permissionCache = null;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function id(): ?string
    {
        return $this->organization?->id;
    }

    public function idOrFail(): string
    {
        return $this->id() ?? throw new MissingTenantContext;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function user(): ?User
    {
        return $this->membership?->user;
    }

    /** @return array<string> */
    public function permissions(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->membership ? $this->membership->permissionNames() : [];
        }

        return $this->permissionCache;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function hasRole(string $key): bool
    {
        return (bool) $this->membership?->roles->contains('key', $key);
    }

    /** null = all branches. */
    public function branchIds(): ?array
    {
        if (! $this->membership || $this->membership->all_branches) {
            return null;
        }

        return $this->membership->branches->pluck('id')->all();
    }

    public function canAccessBranch(?string $branchId): bool
    {
        $ids = $this->branchIds();

        return $ids === null || ($branchId !== null && in_array($branchId, $ids, true));
    }

    public function bypassing(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Run platform-level code without tenant scoping. Every use is deliberate and should be rare. */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;
        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /** Run a callback as a given tenant (jobs, scheduler, platform tasks), restoring the previous context afterwards. */
    public function runAs(Organization $organization, callable $callback, ?Membership $membership = null): mixed
    {
        [$prevOrg, $prevMembership, $prevCache] = [$this->organization, $this->membership, $this->permissionCache];
        $this->set($organization, $membership);
        try {
            return $callback();
        } finally {
            [$this->organization, $this->membership, $this->permissionCache] = [$prevOrg, $prevMembership, $prevCache];
        }
    }
}
