<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /**
     * Update the authenticated user's profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json($user->fresh()->load('organizations'));
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password updated successfully']);
    }

    /** The notification toggles we support, with their defaults. */
    private const NOTIFICATION_DEFAULTS = [
        'new_orders' => true,
        'inventory_alerts' => true,
        'security_updates' => true,
        'marketing' => false,
    ];

    /**
     * Get the authenticated user's notification preferences (merged with defaults).
     */
    public function getNotificationPreferences(Request $request)
    {
        $prefs = $request->user()->notification_preferences ?? [];

        return response()->json(array_merge(self::NOTIFICATION_DEFAULTS, $prefs));
    }

    /**
     * Update the authenticated user's notification preferences.
     */
    public function updateNotificationPreferences(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'new_orders' => 'boolean',
            'inventory_alerts' => 'boolean',
            'security_updates' => 'boolean',
            'marketing' => 'boolean',
        ]);

        $merged = array_merge(self::NOTIFICATION_DEFAULTS, $user->notification_preferences ?? [], $data);
        $user->update(['notification_preferences' => $merged]);

        return response()->json($merged);
    }

    /**
     * Update the active organization's name.
     */
    public function updateOrganization(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $organization = Organization::findOrFail($organizationId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $organization->update(['name' => $data['name']]);

        return response()->json($organization->fresh());
    }
}
