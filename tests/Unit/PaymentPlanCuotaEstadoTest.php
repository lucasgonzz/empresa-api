<?php

namespace Tests\Unit;

use App\Models\PaymentPlanCuota;
use PHPUnit\Framework\TestCase;

class PaymentPlanCuotaEstadoTest extends TestCase
{
    /** @test */
    public function usa_el_estado_canonico_y_admite_el_esquema_historico()
    {
        $this->assertTrue((new PaymentPlanCuota(['estado' => 'pendiente', 'status' => 'paid']))->is_pending());
        $this->assertFalse((new PaymentPlanCuota(['estado' => 'pagado', 'status' => 'pending']))->is_pending());
        $this->assertTrue((new PaymentPlanCuota(['status' => 'pending']))->is_pending());
        $this->assertFalse((new PaymentPlanCuota(['status' => 'paid']))->is_pending());
    }
}
