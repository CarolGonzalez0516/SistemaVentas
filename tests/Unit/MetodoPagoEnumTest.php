<?php

namespace Tests\Unit;

use App\Enums\MetodoPagoEnum;
use PHPUnit\Framework\TestCase;

class MetodoPagoEnumTest extends TestCase
{
    public function test_los_metodos_de_pago_coinciden_con_la_base_de_datos(): void
    {
        $valores = array_map(fn ($caso) => $caso->value, MetodoPagoEnum::cases());

        // La columna metodo_pago de ventas y movimientos solo acepta estos valores
        $this->assertSame(['EFECTIVO', 'TARJETA'], $valores);
    }

    public function test_un_metodo_de_pago_desconocido_no_es_valido(): void
    {
        $this->assertNull(MetodoPagoEnum::tryFrom('CHEQUE'));
        $this->assertSame(MetodoPagoEnum::Efectivo, MetodoPagoEnum::from('EFECTIVO'));
    }
}
