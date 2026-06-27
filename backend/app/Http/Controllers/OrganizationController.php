<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    private const ROLES = ['owner', 'admin', 'viewer'];

    /**
     * List the members of the active organization with their role.
     */
    public function members(Request $request)
    {
        $organization = $this->organization($request);

        $members = $organization->users()
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'is_owner' => $user->id === $organization->owner_id,
            ]);

        return response()->json($members);
    }

    /**
     * Change a member's role. Only owners and admins may do this; only an owner
     * may grant the owner role, and the last owner can't be demoted.
     */
    public function updateMemberRole(Request $request, $userId)
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $actorRole = $this->roleOf($organization, $request->user()->id);

        if (! in_array($actorRole, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'You do not have permission to change roles.'], 403);
        }

        if ($data['role'] === 'owner' && $actorRole !== 'owner') {
            return response()->json(['message' => 'Only an owner can grant the owner role.'], 403);
        }

        if (! $organization->users()->where('users.id', $userId)->exists()) {
            return response()->json(['message' => 'That user is not a member of this organization.'], 404);
        }

        // Don't let the last owner be demoted out of ownership.
        $currentRole = $this->roleOf($organization, (int) $userId);
        if ($currentRole === 'owner' && $data['role'] !== 'owner') {
            $owners = $organization->users()->wherePivot('role', 'owner')->count();
            if ($owners <= 1) {
                return response()->json(['message' => 'An organization must have at least one owner.'], 422);
            }
        }

        $organization->users()->updateExistingPivot($userId, ['role' => $data['role']]);

        return response()->json(['message' => 'Role updated.', 'role' => $data['role']]);
    }

    private function organization(Request $request): Organization
    {
        return Organization::findOrFail($request->header('X-Organization-Id'));
    }

    private function roleOf(Organization $organization, int $userId): ?string
    {
        $member = $organization->users()->where('users.id', $userId)->first();

        return $member?->pivot->role;
    }
}
