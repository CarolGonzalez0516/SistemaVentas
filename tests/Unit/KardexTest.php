<?php

namespace Tests\Unit;

use App\Models\Kardex;
use Tests\TestCase;

class KardexTest extends TestCase
{
    public function test_el_costo_total_es_el_saldo_por_el_costo_unitario(): void
    {
        $kardex = new Kardex();
        $kardex->saldo = 8;
        $kardex->costo_unitario = 2.5;

        $this->assertEquals(20.0, $kardex->costo_total);
    }
}
