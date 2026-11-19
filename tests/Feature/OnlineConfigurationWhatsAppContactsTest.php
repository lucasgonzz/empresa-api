<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OnlineConfigurationWhatsAppContactsTest extends TestCase
{
    use DatabaseTransactions;

    private function configuracion()
    {
        $usuario = User::findOrFail(500);
        $this->actingAs($usuario, 'web');
        return OnlineConfiguration::create(['user_id' => $usuario->id]);
    }

    private function guardar($config, array $cambios, array $omitidos = [])
    {
        $payload = array_merge($config->fresh()->toArray(), $cambios);
        foreach ($omitidos as $campo) {
            unset($payload[$campo]);
        }
        return $this->putJson('api/online-configuration/'.$config->id, $payload);
    }

    public function test_normaliza_dos_destinos_sin_modificar_el_telefono_general_ni_los_avisos()
    {
        $config = $this->configuracion();
        $telefono = User::findOrFail(500)->phone;
        $config->enviar_whatsapp_al_terminar_pedido = 1;
        $config->save();
        $this->guardar($config, [
            'whatsapp_consultations_phone' => '+54 (9) 11-2345.6789',
            'whatsapp_seller_phone' => '00 34 (91) 234-5678',
        ])->assertOk()->assertJsonPath('model.whatsapp_consultations_phone', '5491123456789');

        $this->assertSame('34912345678', $config->fresh()->whatsapp_seller_phone);
        $this->assertSame($telefono, User::findOrFail(500)->phone);
        $this->assertEquals(1, $config->fresh()->enviar_whatsapp_al_terminar_pedido);
    }

    public function test_el_comercio_nuevo_no_tiene_destinos_activados()
    {
        $config = $this->configuracion()->fresh();
        $this->assertNull($config->whatsapp_consultations_phone);
        $this->assertNull($config->whatsapp_seller_phone);
    }

    public function test_los_campos_omitidos_se_conservan_y_cada_destino_se_puede_borrar_solo()
    {
        $config = $this->configuracion();
        $config->update(['whatsapp_consultations_phone' => '5491123456789', 'whatsapp_seller_phone' => '34912345678']);
        $this->guardar($config, [], ['whatsapp_consultations_phone', 'whatsapp_seller_phone'])->assertOk();
        $this->assertSame('5491123456789', $config->fresh()->whatsapp_consultations_phone);
        $this->assertSame('34912345678', $config->fresh()->whatsapp_seller_phone);

        $this->guardar($config, ['whatsapp_consultations_phone' => '  '], ['whatsapp_seller_phone'])->assertOk();
        $this->assertNull($config->fresh()->whatsapp_consultations_phone);
        $this->assertSame('34912345678', $config->fresh()->whatsapp_seller_phone);
        $this->guardar($config, ['whatsapp_seller_phone' => null])->assertOk();
        $this->assertNull($config->fresh()->whatsapp_seller_phone);
    }

    public function destinos_invalidos()
    {
        return [
            'letras' => ['54911hola'],
            'url' => ['https://wa.me/5491123456789'],
            'array' => [['5491123456789']],
            'objeto' => [(object) ['phone' => '5491123456789']],
            'numero json' => [5491123456789],
            'cero local' => ['01123456789'],
            'corto' => ['1234567'],
            'largo' => ['1234567890123456'],
            'extension' => ['5491123456789 ext 1'],
            'doble prefijo' => ['+005491123456789'],
        ];
    }

    /** @dataProvider destinos_invalidos */
    public function test_rechaza_destinos_invalidos_sin_guardar_el_otro($invalido)
    {
        $config = $this->configuracion();
        $config->update(['whatsapp_consultations_phone' => '5491123456789', 'whatsapp_seller_phone' => '34912345678']);
        $this->guardar($config, [
            'whatsapp_consultations_phone' => '5491198765432',
            'whatsapp_seller_phone' => $invalido,
        ])->assertStatus(422)->assertJsonValidationErrors('whatsapp_seller_phone');
        $this->assertSame('5491123456789', $config->fresh()->whatsapp_consultations_phone);
        $this->assertSame('34912345678', $config->fresh()->whatsapp_seller_phone);
    }

    public function test_no_puede_cambiar_destinos_de_otro_negocio()
    {
        $this->configuracion();
        $otro = OnlineConfiguration::create(['user_id' => 501, 'whatsapp_seller_phone' => '34912345678']);
        $this->guardar($otro, ['whatsapp_seller_phone' => '5491123456789'])->assertNotFound();
        $this->assertSame('34912345678', $otro->fresh()->whatsapp_seller_phone);
    }
}
