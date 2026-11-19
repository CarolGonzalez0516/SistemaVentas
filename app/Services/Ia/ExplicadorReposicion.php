<?php

namespace App\Services\Ia;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redacta en lenguaje natural la explicación de las sugerencias de reposición.
 *
 * - Con IA_LLM_DRIVER=anthropic y ANTHROPIC_API_KEY definida, usa el modelo de lenguaje.
 * - Si no hay llave o la API falla, devuelve una explicación generada por reglas.
 * - Al modelo solo se envían productos y cifras de inventario: nunca datos de clientes.
 */
class ExplicadorReposicion
{
    /**
     * @return array{fuente: string, texto: string}
     */
    public function explicar(Collection $sugerencias, int $ventanaDias): array
    {
        if ($sugerencias->isEmpty()) {
            return ['fuente' => 'reglas', 'texto' => 'Con las ventas de los últimos '.$ventanaDias.' días ningún producto necesita reposición por ahora.'];
        }

        $config = config('ia.llm');

        if (($config['driver'] ?? 'none') === 'anthropic' && ! empty($config['api_key'])) {
            try {
                $respuesta = Http::timeout($config['timeout'])
                    ->withHeaders([
                        'x-api-key' => $config['api_key'],
                        'anthropic-version' => '2023-06-01',
                    ])
                    ->post($config['url'], [
                        'model' => $config['model'],
                        'max_tokens' => $config['max_tokens'],
                        'system' => 'Eres el asistente de inventario de una tienda de abarrotes en Colombia. '
                            .'Explica en español, en máximo 5 frases cortas, qué productos conviene reponer primero y por qué, '
                            .'usando SOLO las cifras recibidas. No inventes datos. Recuerda que la decisión la aprueba el dueño.',
                        'messages' => [[
                            'role' => 'user',
                            'content' => 'Ventana de análisis: '.$ventanaDias." días.\nSugerencias (JSON):\n"
                                .json_encode($this->datosParaModelo($sugerencias), JSON_UNESCAPED_UNICODE),
                        ]],
                    ])
                    ->throw();

                $texto = trim((string) data_get($respuesta->json(), 'content.0.text', ''));

                if ($texto !== '') {
                    return ['fuente' => 'llm:'.$config['model'], 'texto' => $texto];
                }
            } catch (Throwable $e) {
                Log::warning('IA reposición: el modelo de lenguaje no respondió, se usa la explicación por reglas.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['fuente' => 'reglas', 'texto' => $this->explicacionPorReglas($sugerencias, $ventanaDias)];
    }

    /**
     * Solo los campos necesarios: producto y cifras de inventario.
     */
    private function datosParaModelo(Collection $sugerencias): array
    {
        return $sugerencias->take(10)->map(fn ($s) => [
            'producto' => $s['producto'],
            'stock_actual' => $s['stock_actual'],
            'promedio_diario' => $s['promedio_diario'],
            'dias_cobertura' => $s['dias_cobertura'],
            'cantidad_sugerida' => $s['cantidad_sugerida'],
            'prioridad' => $s['prioridad'],
        ])->values()->all();
    }

    private function explicacionPorReglas(Collection $sugerencias, int $ventanaDias): string
    {
        $altas = $sugerencias->where('prioridad', 'alta');
        $texto = 'Según las ventas de los últimos '.$ventanaDias.' días, '.$sugerencias->count().' producto(s) necesitan reposición.';

        if ($altas->isNotEmpty()) {
            $texto .= ' Urgente: '.$altas->map(fn ($s) => $s['producto'].' (alcanza para '.$s['dias_cobertura'].' días, pedir '.$s['cantidad_sugerida'].')')->implode('; ').'.';
        }

        return $texto.' La sugerencia debe ser aprobada por el propietario antes de comprar.';
    }
}
