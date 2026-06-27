<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $notifications = Notification::where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json($notifications);
    }

    public function markAsRead(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $notification = Notification::where('organization_id', $organizationId)->findOrFail($id);
        
        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
