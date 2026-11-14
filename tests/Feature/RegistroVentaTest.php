<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Inventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HU-03: Registrar una venta en el punto de venta.
 */
class RegistroVentaTest extends TestCase
{
    use RefreshDatabase;

    protected User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'crear-venta']);
        $this->cajero = User::factory()->create();
        $this->cajero->givePermissionTo('crear-venta');
        $this->actingAs($this->cajero);
    }

    /**
     * Crea un cliente, un comprobante y un producto con 10 unidades en inventario.
     *
     * @return array{cliente_id:int, comprobante_id:int, producto_id:int}
     */
    private function crearDatosDeVenta(): array
    {
        $documentoId = DB::table('documentos')->insertGetId(['nombre' => 'CC']);
        $personaId = DB::table('personas')->insertGetId([
            'razon_social' => 'Cliente de prueba',
            'tipo' => 'NATURAL',
            'documento_id' => $documentoId,
            'numero_documento' => '1001',
        ]);
        $clienteId = DB::table('clientes')->insertGetId(['persona_id' => $personaId]);
        $comprobanteId = DB::table('comprobantes')->insertGetId(['nombre' => 'Boleta']);

        $caracteristicaId = DB::table('caracteristicas')->insertGetId(['nombre' => 'Unidad']);
        $presentacionId = DB::table('presentaciones')->insertGetId([
            'caracteristica_id' => $caracteristicaId,
            'sigla' => 'UND',
        ]);
        $productoId = DB::table('productos')->insertGetId([
            'codigo' => 'P-001',
            'nombre' => 'Arroz 500 g',
            'precio' => 5,
            'presentacione_id' => $presentacionId,
        ]);
        $ubicacionId = DB::table('ubicaciones')->insertGetId(['nombre' => 'Bodega']);

        // Apertura del kardex: va antes del inventario porque el InventarioObserver
        // calcula el precio de venta con el último costo unitario del kardex
        DB::table('kardex')->insert([
            'producto_id' => $productoId,
            'tipo_transaccion' => 'APERTURA',
            'descripcion_transaccion' => 'Apertura de inventario',
            'entrada' => 10,
            'saldo' => 10,
            'costo_unitario' => 3,
        ]);

        Inventario::create([
            'producto_id' => $productoId,
            'ubicacione_id' => $ubicacionId,
            'cantidad' => 10,
        ]);

        return [
            'cliente_id' => $clienteId,
            'comprobante_id' => $comprobanteId,
            'producto_id' => $productoId,
        ];
    }

    private function datosDelFormulario(array $datos, array $productos): array
    {
        return array_merge([
            'cliente_id' => $datos['cliente_id'],
            'comprobante_id' => $datos['comprobante_id'],
            'metodo_pago' => 'EFECTIVO',
            'subtotal' => 10,
            'impuesto' => 0,
            'total' => 10,
            'monto_recibido' => 20,
            'vuelto_entregado' => 10,
        ], $productos);
    }

    public function test_sin_caja_abierta_no_se_puede_vender(): void
    {
        $datos = $this->crearDatosDeVenta();

        $this->post(route('ventas.store'), $this->datosDelFormulario($datos, [
            'arrayidproducto' => [$datos['producto_id']],
            'arraycantidad' => [2],
            'arrayprecioventa' => [5],
        ]))->assertRedirect(route('cajas.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_una_venta_sin_productos_se_rechaza(): void
    {
        $datos = $this->crearDatosDeVenta();
        Caja::create(['saldo_inicial' => 50]);

        $this->post(route('ventas.store'), $this->datosDelFormulario($datos, []))
            ->assertSessionHasErrors(['arrayidproducto', 'arraycantidad', 'arrayprecioventa']);

        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_cantidades_que_no_coinciden_con_los_productos_se_rechazan(): void
    {
        $datos = $this->crearDatosDeVenta();
        Caja::create(['saldo_inicial' => 50]);

        $this->post(route('ventas.store'), $this->datosDelFormulario($datos, [
            'arrayidproducto' => [$datos['producto_id']],
            'arraycantidad' => [2, 3],
            'arrayprecioventa' => [5],
        ]))->assertSessionHasErrors(['arraycantidad']);

        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_una_venta_valida_baja_el_stock_y_registra_kardex_y_caja(): void
    {
        $datos = $this->crearDatosDeVenta();
        $caja = Caja::create(['saldo_inicial' => 50]);

        $this->post(route('ventas.store'), $this->datosDelFormulario($datos, [
            'arrayidproducto' => [$datos['producto_id']],
            'arraycantidad' => [2],
            'arrayprecioventa' => [5],
        ]))->assertRedirect(route('movimientos.index', ['caja_id' => $caja->id]))
            ->assertSessionHas('success', 'Venta registrada');

        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('producto_venta', [
            'producto_id' => $datos['producto_id'],
            'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('inventario', [
            'producto_id' => $datos['producto_id'],
            'cantidad' => 8,
        ]);
        $this->assertDatabaseHas('kardex', [
            'producto_id' => $datos['producto_id'],
            'tipo_transaccion' => 'VENTA',
            'salida' => 2,
            'saldo' => 8,
        ]);
        $this->assertDatabaseHas('movimientos', [
            'caja_id' => $caja->id,
            'tipo' => 'VENTA',
            'metodo_pago' => 'EFECTIVO',
        ]);
    }
}
