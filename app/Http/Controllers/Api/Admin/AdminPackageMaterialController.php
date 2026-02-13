<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPackageMaterialController extends Controller
{
    // GET /api/admin/packages/{package}/materials
    public function index(Package $package)
    {
        $items = $package->materials()
            ->select('materials.id', 'materials.type', 'materials.title', 'materials.is_active', 'materials.is_free')
            ->get()
            ->map(fn($m) => [
                'material_id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'is_active' => (bool) $m->is_active,
                'is_free' => (bool) $m->is_free,
                'sort_order' => (int) ($m->pivot->sort_order ?? 1),
            ])->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    // PUT /api/admin/packages/{package}/materials
    public function sync(Request $request, Package $package)
    {
        $data = $request->validate([
            'materials' => ['required', 'array', 'min:0'],
            'materials.*.material_id' => ['required', 'integer', 'exists:materials,id'],
            'materials.*.sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        $rows = collect($data['materials'])
            ->map(fn($m) => [
                'material_id' => (int) $m['material_id'],
                'sort_order'  => (int) ($m['sort_order'] ?? 1),
            ])
            // hilangkan duplikat material_id (ambil yang terakhir)
            ->unique('material_id')
            // rapihin urutan: sort lalu reindex 1..n
            ->sortBy('sort_order')
            ->values()
            ->map(fn($m, $i) => [
                'material_id' => $m['material_id'],
                'sort_order'  => $i + 1,
            ]);

        $sync = $rows->mapWithKeys(fn($m) => [
            $m['material_id'] => ['sort_order' => $m['sort_order']]
        ])->toArray();

        DB::transaction(function () use ($package, $sync) {
            $package->materials()->sync($sync);
        });

        $items = $package->materials()
            ->select('materials.id', 'materials.type', 'materials.title', 'materials.is_active', 'materials.is_free')
            ->get()
            ->map(fn($m) => [
                'material_id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'is_active' => (bool) $m->is_active,
                'is_free' => (bool) $m->is_free,
                'sort_order' => (int) ($m->pivot->sort_order ?? 1),
            ])->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function destroy(Package $package, Material $material)
    {
        return DB::transaction(function () use ($package, $material) {

            // Pastikan material memang attached
            $exists = $package->materials()
                ->where('materials.id', $material->id)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Material not attached to this package'
                ], 404);
            }

            // Detach dulu
            $package->materials()->detach($material->id);

            // Ambil sisa material sesuai urutan pivot saat ini
            $remainingIds = $package->materials()
                ->orderByPivot('sort_order')
                ->pluck('materials.id')
                ->values();

            // Reindex jadi 1..n
            $sync = $remainingIds->mapWithKeys(fn($id, $i) => [
                (int) $id => ['sort_order' => $i + 1]
            ])->toArray();

            // Update pivot sort_order tanpa nambah/hapus relasi lain
            if (!empty($sync)) {
                $package->materials()->sync($sync);
            }

            return response()->json([
                'success' => true,
                'message' => 'Material detached & reordered successfully',
            ]);
        });
    }
}
