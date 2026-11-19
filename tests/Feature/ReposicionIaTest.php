<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Función de IA: sugerencia de reposición a partir del historial de ventas.
 * El modelo de lenguaje se simula con Http::fake(), así la prueba no usa la llave real.
 */
class ReposicionIaTest extends TestCase
{
    use RefreshDatabase;

    protected User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        config([
            'ia.ventana_dias' => 28,
            'ia.dias_cobertura_objetivo' => 14,
            'ia.dias_alerta' => 5,
            'ia.llm.driver' => 'none',
            'ia.llm.api_key' => null,
        ]);
    }

    /**
     * Arroz: 56 unidades vendidas en 28 días (2/día) y 4 en stock -> urgente, pedir 24.
     * Sal: sin ventas y 30 en stock -> no necesita reposición.
     */
    private function crearHistorial(): array
    {
        $this->actingAs($this->usuario);

        $documentoId = DB::table('documentos')->insertGetId(['nombre' => 'CC']);
        $personaId = DB::table('personas')->insertGetId([
            'razon_social' => 'Cliente Confidencial',
            'tipo' => 'NATURAL',
            'documento_id' => $documentoId,
            'numero_documento' => '999',
        ]);
        $clienteId = DB::table('clientes')->insertGetId(['persona_id' => $personaId]);
        $comprobanteId = DB::table('comprobantes')->insertGetId(['nombre' => 'Boleta']);
        $caracteristicaId = DB::table('caracteristicas')->insertGetId(['nombre' => 'Unidad']);
        $presentacionId = DB::table('presentaciones')->insertGetId(['caracteristica_id' => $caracteristicaId, 'sigla' => 'UND']);
        $ubicacionId = DB::table('ubicaciones')->insertGetId(['nombre' => 'Bodega']);
        $caja = Caja::create(['saldo_inicial' => 0]);

        $arroz = DB::table('productos')->insertGetId(['codigo' => 'P-ARROZ', 'nombre' => 'Arroz 500 g', 'precio' => 5, 'presentacione_id' => $presentacionId]);
        $sal = DB::table('productos')->insertGetId(['codigo' => 'P-SAL', 'nombre' => 'Sal 1 kg', 'precio' => 2, 'presentacione_id' => $presentacionId]);

        DB::table('inventario')->insert([
            ['producto_id' => $arroz, 'ubicacione_id' => $ubicacionId, 'cantidad' => 4],
            ['producto_id' => $sal, 'ubicacione_id' => $ubicacionId, 'cantidad' => 30],
        ]);

        // 4 ventas de 14 unidades de arroz dentro de la ventana y 1 venta antigua que no cuenta
        foreach ([2, 9, 16, 23, 60] as $i => $diasAtras) {
            $ventaId = DB::table('ventas')->insertGetId([
                'cliente_id' => $clienteId,
                'user_id' => $this->usuario->id,
                'caja_id' => $caja->id,
                'comprobante_id' => $comprobanteId,
                'numero_comprobante' => 'B001 - '.str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT),
                'metodo_pago' => 'EFECTIVO',
                'fecha_hora' => Carbon::now()->subDays($diasAtras),
                'subtotal' => 70, 'impuesto' => 0, 'total' => 70,
                'monto_recibido' => 70, 'vuelto_entregado' => 0,
            ]);
            DB::table('producto_venta')->insert(['venta_id' => $ventaId, 'producto_id' => $arroz, 'cantidad' => 14, 'precio_venta' => 5]);
        }

        return ['arroz' => $arroz, 'sal' => $sal];
    }

    public function test_sin_autenticacion_no_se_accede_al_endpoint(): void
    {
        $this->getJson('/api/ia/reposicion')->assertUnauthorized();
    }

    public function test_calcula_la_sugerencia_con_el_promedio_movil(): void
    {
        $productos = $this->crearHistorial();
        Sanctum::actingAs($this->usuario);

        $respuesta = $this->getJson('/api/ia/reposicion')->assertOk();

        $respuesta->assertJsonPath('ventana_dias', 28)
            ->assertJsonPath('total_sugerencias', 1)
            ->assertJsonPath('sugerencias.0.producto_id', $productos['arroz'])
            ->assertJsonPath('sugerencias.0.unidades_vendidas', 56)
            ->assertJsonPath('sugerencias.0.promedio_diario', 2)
            ->assertJsonPath('sugerencias.0.dias_cobertura', 2)
            ->assertJsonPath('sugerencias.0.cantidad_sugerida', 24)
            ->assertJsonPath('sugerencias.0.prioridad', 'alta')
            ->assertJsonPath('fuente_explicacion', 'reglas');

        // Solo sugiere: el inventario no cambia
        $this->assertDatabaseHas('inventario', ['producto_id' => $productos['arroz'], 'cantidad' => 4]);
    }

    public function test_usa_el_modelo_de_lenguaje_sin_enviar_datos_de_clientes(): void
    {
        $this->crearHistorial();
        config(['ia.llm.driver' => 'anthropic', 'ia.llm.api_key' => 'llave-de-prueba', 'ia.llm.model' => 'modelo-prueba']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Reponga primero el arroz: quedan 2 días.']]]),
        ]);
        Sanctum::actingAs($this->usuario);

        $this->getJson('/api/ia/reposicion')
            ->assertOk()
            ->assertJsonPath('explicacion', 'Reponga primero el arroz: quedan 2 días.')
            ->assertJsonPath('fuente_explicacion', 'llm:modelo-prueba');

        Http::assertSent(function (HttpRequest $request) {
            return $request->hasHeader('x-api-key', 'llave-de-prueba')
                && str_contains($request->body(), 'Arroz 500 g')
                && ! str_contains($request->body(), 'Cliente Confidencial');
        });
    }

    public function test_si_la_api_falla_responde_con_la_explicacion_por_reglas(): void
    {
        $this->crearHistorial();
        config(['ia.llm.driver' => 'anthropic', 'ia.llm.api_key' => 'llave-de-prueba']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'caído'], 500)]);
        Sanctum::actingAs($this->usuario);

        $this->getJson('/api/ia/reposicion')
            ->assertOk()
            ->assertJsonPath('fuente_explicacion', 'reglas')
            ->assertJsonPath('total_sugerencias', 1);
    }
}
