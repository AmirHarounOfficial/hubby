<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        // Return a flat list, the frontend will build the tree representation.
        return Category::where('organization_id', $organizationId)->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $category = Category::create([
            'organization_id' => $organizationId,
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . uniqid(), // Ensure uniqueness
            'description' => $request->description,
            'parent_id' => $request->parent_id,
        ]);

        return response()->json($category, 201);
    }

    public function show(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $category = Category::where('organization_id', $organizationId)->findOrFail($id);
        
        return response()->json($category);
    }

    public function update(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $category = Category::where('organization_id', $organizationId)->findOrFail($id);
        
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id|not_in:' . $id,
        ]);

        // Prevent infinite loops: check if the new parent is a descendant of this category
        if ($request->parent_id) {
            $currentParentId = $request->parent_id;
            while ($currentParentId) {
                if ($currentParentId == $id) {
                    return response()->json(['message' => 'Cannot assign a category as a child of itself or its descendants.'], 400);
                }
                $parent = Category::find($currentParentId);
                $currentParentId = $parent ? $parent->parent_id : null;
            }
        }

        $category->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . uniqid(),
            'description' => $request->description,
            'parent_id' => $request->parent_id,
        ]);

        return response()->json($category);
    }

    public function destroy(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $category = Category::where('organization_id', $organizationId)->findOrFail($id);
        
        $strategy = $request->query('strategy', 'move_to_root'); // 'cascade' or 'move_to_root'

        if ($strategy === 'move_to_root') {
            Category::where('parent_id', $id)->update(['parent_id' => null]);
        } 
        // If strategy is 'cascade', the database foreign key onDelete('cascade') will handle it automatically.

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }
}
