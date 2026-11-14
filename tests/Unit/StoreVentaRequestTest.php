<?php

namespace Tests\Unit;

use App\Http\Requests\StoreVentaRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreVentaRequestTest extends TestCase
{
    public function test_una_venta_sin_productos_no_pasa_la_validacion(): void
    {
        $request = new StoreVentaRequest();

        $validator = Validator::make([
            'metodo_pago' => 'EFECTIVO',
            'subtotal' => 10,
            'impuesto' => 0,
            'total' => 10,
            'monto_recibido' => 10,
            'vuelto_entregado' => 0,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('arrayidproducto', $validator->errors()->toArray());
        $this->assertArrayHasKey('arraycantidad', $validator->errors()->toArray());
        $this->assertArrayHasKey('arrayprecioventa', $validator->errors()->toArray());
    }
}
