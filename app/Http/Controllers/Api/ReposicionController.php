<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ia\ExplicadorReposicion;
use App\Services\Ia\ReposicionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Función de IA: sugerencia de reposición de inventario.
 * GET /api/ia/reposicion          (token Sanctum)
 * GET /admin/ia/reposicion        (sesión web)
 */
class ReposicionController extends Controller
{
    public function __invoke(Request $request, ReposicionService $reposicion, ExplicadorReposicion $explicador): JsonResponse
    {
        $datos = $request->validate([
            'dias' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $ventana = (int) ($datos['dias'] ?? config('ia.ventana_dias', 28));
        $sugerencias = $reposicion->sugerencias($ventana);
        $explicacion = $explicador->explicar($sugerencias, $ventana);

        return response()->json([
            'generado_en' => now()->toIso8601String(),
            'ventana_dias' => $ventana,
            'total_sugerencias' => $sugerencias->count(),
            'sugerencias' => $sugerencias,
            'explicacion' => $explicacion['texto'],
            'fuente_explicacion' => $explicacion['fuente'],
            'aviso' => 'Son sugerencias: no se crean órdenes de compra ni se modifica el inventario sin aprobación del propietario.',
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
