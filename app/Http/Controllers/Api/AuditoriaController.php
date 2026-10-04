<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $query = Auditoria::with('usuario:id,username,rol')
            ->orderByDesc('id_auditoria');

        if ($request->filled('modulo')) {
            $query->where('modulo', $request->query('modulo'));
        }

        if ($request->filled('accion')) {
            $query->where('accion', $request->query('accion'));
        }

        if ($request->filled('desde')) {
            $query->whereDate('created_at', '>=', $request->query('desde'));
        }

        if ($request->filled('hasta')) {
            $query->whereDate('created_at', '<=', $request->query('hasta'));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 50);

        return response()->json($query->paginate($perPage));
    }
}
