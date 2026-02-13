<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\Package;

class UserMaterialController extends Controller
{
    // GET /api/materials?type=ebook|video&search=...
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'in:ebook,video'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $userId = (int) $request->user()->id;

        // ✅ ambil semua material yang user boleh akses (free / punya paket aktif)
        $ids = $this->accessibleMaterialIds($userId);

        $q = Material::query()
            ->whereIn('id', $ids)
            ->when($request->type, fn($qq) => $qq->where('type', $request->type))
            ->when($request->search, fn($qq) => $qq->where('title', 'like', "%{$request->search}%"))
            ->withCount(['parts as active_parts_count' => fn($qq) => $qq->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(20);

        $q->getCollection()->transform(function (Material $m) {
            return [
                'id' => (int) $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'description' => $m->description,
                'cover_url' => $m->cover_url,
                'sort_order' => (int) $m->sort_order,
                'is_free' => (bool) $m->is_free,
                'has_parts' => $m->type === 'video' ? ((int) $m->active_parts_count > 0) : false,
            ];
        });

        return response()->json(['success' => true, 'data' => $q]);
    }

    // GET /api/materials/{material}
    public function show(Request $request, Material $material)
    {
        abort_unless($material->is_active, 404);

        $userId = (int) $request->user()->id;

        // ✅ 1 pintu: cek akses
        abort_unless($this->userCanAccessMaterial($userId, (int) $material->id, (bool) $material->is_free), 403);

        $data = [
            'id' => (int) $material->id,
            'type' => $material->type,
            'title' => $material->title,
            'description' => $material->description,
            'cover_url' => $material->cover_url,
            'sort_order' => (int) $material->sort_order,
            'is_free' => (bool) $material->is_free,
        ];

        if ($material->type === 'ebook') {
            $data['ebook_url'] = $material->ebook_url;
            $data['parts'] = [];
        } else {
            $data['ebook_url'] = null;
            $data['parts'] = $material->parts()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn($p) => [
                    'id' => (int) $p->id,
                    'title' => $p->title,
                    'video_url' => $p->video_url,
                    'duration_seconds' => (int) ($p->duration_seconds ?? 0),
                    'sort_order' => (int) $p->sort_order,
                ])
                ->values();
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * List material IDs yang user boleh akses:
     * - material free
     * - atau material yang di-assign ke package yang user punya & masih aktif (starts_at/ends_at)
     */
    private function accessibleMaterialIds(int $userId): Collection
    {
        $now = now();

        return DB::table('materials')
            ->where('materials.is_active', true)
            ->where(function ($q) use ($userId, $now) {
                $q->where('materials.is_free', true)
                    ->orWhereExists(function ($sq) use ($userId, $now) {
                        $sq->selectRaw('1')
                            ->from('package_materials as pm')
                            ->join('user_packages as up', 'up.package_id', '=', 'pm.package_id')
                            ->whereColumn('pm.material_id', 'materials.id')
                            ->where('up.user_id', $userId)
                            ->where(function ($x) use ($now) {
                                $x->whereNull('up.starts_at')->orWhere('up.starts_at', '<=', $now);
                            })
                            ->where(function ($x) use ($now) {
                                $x->whereNull('up.ends_at')->orWhere('up.ends_at', '>', $now);
                            });
                    });
            })
            ->pluck('materials.id')
            ->map(fn($id) => (int) $id);
    }

    public function byPackage(Package $package)
    {
        $items = $package->materials()
            ->where('materials.is_active', true)
            ->select('materials.id', 'materials.type', 'materials.title', 'materials.cover_url', 'materials.is_free')
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'cover_url' => $m->cover_url,
                'is_free' => (bool) $m->is_free,
                'sort_order' => (int) ($m->pivot->sort_order ?? 1),
            ])->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * Cek akses single material.
     */
    private function userCanAccessMaterial(int $userId, int $materialId, bool $isFree): bool
    {
        if ($isFree) return true;

        $now = now();

        return DB::table('package_materials as pm')
            ->join('user_packages as up', 'up.package_id', '=', 'pm.package_id')
            ->where('pm.material_id', $materialId)
            ->where('up.user_id', $userId)
            ->where(function ($x) use ($now) {
                $x->whereNull('up.starts_at')->orWhere('up.starts_at', '<=', $now);
            })
            ->where(function ($x) use ($now) {
                $x->whereNull('up.ends_at')->orWhere('up.ends_at', '>', $now);
            })
            ->exists();
    }
}
