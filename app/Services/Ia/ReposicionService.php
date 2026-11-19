<?php

namespace App\Services\Ia;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula sugerencias de reposición a partir del historial de ventas
 * (promedio móvil de unidades vendidas por día).
 *
 * Solo sugiere: no crea órdenes de compra ni modifica el inventario.
 * La decisión final siempre la aprueba una persona.
 */
class ReposicionService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function sugerencias(?int $ventanaDias = null): Collection
    {
        $ventanaDias = max(1, $ventanaDias ?? (int) config('ia.ventana_dias', 28));
        $diasObjetivo = (int) config('ia.dias_cobertura_objetivo', 14);
        $diasAlerta = (int) config('ia.dias_alerta', 5);
        $desde = Carbon::now()->subDays($ventanaDias);

        $vendidos = DB::table('producto_venta')
            ->join('ventas', 'ventas.id', '=', 'producto_venta.venta_id')
            ->where('ventas.fecha_hora', '>=', $desde)
            ->groupBy('producto_venta.producto_id')
            ->select('producto_venta.producto_id', DB::raw('SUM(producto_venta.cantidad) as unidades'))
            ->pluck('unidades', 'producto_id');

        return DB::table('productos')
            ->leftJoin('inventario', 'inventario.producto_id', '=', 'productos.id')
            ->select('productos.id', 'productos.codigo', 'productos.nombre', 'inventario.cantidad as stock', 'inventario.cantidad_minima')
            ->get()
            ->map(function ($p) use ($vendidos, $ventanaDias, $diasObjetivo, $diasAlerta) {
                $unidades = (int) ($vendidos[$p->id] ?? 0);
                $stock = (int) ($p->stock ?? 0);
                $promedio = round($unidades / $ventanaDias, 2);
                $cobertura = $promedio > 0 ? round($stock / $promedio, 1) : null;
                $sugerido = (int) max(0, ceil($promedio * $diasObjetivo) - $stock);

                if ($p->cantidad_minima !== null && $stock < (int) $p->cantidad_minima) {
                    $sugerido = max($sugerido, (int) $p->cantidad_minima - $stock);
                }

                $prioridad = match (true) {
                    $sugerido === 0 => 'sin_reponer',
                    $cobertura !== null && $cobertura <= $diasAlerta => 'alta',
                    default => 'media',
                };

                return [
                    'producto_id' => $p->id,
                    'codigo' => $p->codigo,
                    'producto' => $p->nombre,
                    'stock_actual' => $stock,
                    'unidades_vendidas' => $unidades,
                    'promedio_diario' => $promedio,
                    'dias_cobertura' => $cobertura,
                    'cantidad_sugerida' => $sugerido,
                    'prioridad' => $prioridad,
                ];
            })
            ->filter(fn ($s) => $s['cantidad_sugerida'] > 0)
            ->sortBy([
                fn ($a, $b) => ($a['prioridad'] === 'alta' ? 0 : 1) <=> ($b['prioridad'] === 'alta' ? 0 : 1),
                fn ($a, $b) => ($a['dias_cobertura'] ?? INF) <=> ($b['dias_cobertura'] ?? INF),
            ])
            ->values();
    }
}
