<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\AfipTicketController;
use App\Models\AfipTicket;
use Tests\EmpresaTestCase;

/**
 * Tests del bloqueo de borrado de un `AfipTicket` que ya tiene CAE autorizado por ARCA.
 *
 * Bug real (cliente masquito, producción, confirmado 11/9/2026): se facturó una venta, ARCA
 * autorizó el comprobante (CAE real), y 21 segundos después algo llamó a
 * `AfipTicketController::destroy()` y lo borró. `AfipTicket` usa `SoftDeletes`, así que el borrado
 * solo setea `deleted_at` -el comprobante NO se puede "deshacer" así: ante ARCA sigue vigente-,
 * pero al quedar con `deleted_at` seteado se vuelve invisible para el Libro IVA y los dos
 * exportadores de TXT de AFIP (que respetan el scope de `SoftDeletes`, correctamente). Resultado:
 * ARCA queda con un comprobante autorizado que el sistema nunca vuelve a declarar.
 *
 * Decisión de Lucas (textual): "Bloqueá el borrado, no generes NC automática". O sea: `destroy()`
 * tiene que rechazar el borrado de un ticket con CAE con un error explícito (422), sin generar
 * ninguna Nota de Crédito ni disparar otro flujo. Anular un comprobante autorizado sigue siendo,
 * a propósito, una acción manual (emitir la NC correspondiente).
 *
 * Como se ejercita: se llama a `AfipTicketController::destroy()` DIRECTO (mismo criterio "sin red"
 * que el resto de esta carpeta, ver docblock de `Importe_Personalizado_Por_Alicuota_Test`), no por
 * HTTP -esta carpeta no tiene precedente de tests vía ruta/middleware, y acá no hace falta: no hay
 * nada en el `Request` que la guarda necesite. El `AfipTicket` sí se persiste de verdad (`save()`)
 * porque hace falta poder confirmar con `assertSoftDeleted`/`assertNotSoftDeleted` que el borrado
 * bloqueado NO tocó `deleted_at`.
 *
 * 🔴 El criterio de "tiene CAE" tiene que ser el mismo que ya usa el resto del código para la
 * misma pregunta -nunca `is_null($model->cae)` a secas-: `ConsolidarFacturacionHelper` (líneas
 * ~74-78 y ~366-369), `ComprobanteImputadoHelper::ultimo_comprobante_imputado()` y
 * `AfipTicketController::problemas_al_facturar()` (arriba en este mismo archivo) coinciden en que
 * NULL y `''` son la MISMA cosa ("no tiene CAE autorizado"): todos filtran con
 * `whereNotNull('cae')->where('cae', '!=', '')` o el equivalente inverso
 * (`whereNull('cae')->orWhere('cae', '')`). Por eso el caso de `cae = ''` de acá abajo espera
 * exactamente el mismo resultado que `cae = null` -se sigue pudiendo borrar, 200, `deleted_at`
 * seteado-, y NO el resultado del ticket con CAE real: si la guarda se escribiera como
 * `is_null($model->cae)` a secas, un ticket con `cae = ''` pasaría la guarda como si "tuviera
 * CAE" (`is_null('')` es `false`) y este test lo detecta, porque esperar 200 y recibir 422 hace
 * fallar la aserción.
 *
 * Verificado a mano (no automatizado en el test): comentando la guarda nueva en `destroy()`, el
 * test `un_ticket_con_cae_autorizado_no_se_puede_borrar` de abajo pasa a rojo (la respuesta da 200
 * y el ticket queda borrado); con la guarda puesta, vuelve a verde. Los otros dos quedan en verde
 * en los dos casos, como corresponde (no dependen de la guarda nueva).
 *
 * @group facturacion
 * @group afip
 */
class Destroy_Afip_Ticket_Bloquea_Con_Cae_Test extends EmpresaTestCase
{
    /**
     * Crea y persiste un `AfipTicket` mínimo con el `cae` pedido. Se persiste de verdad (no en
     * memoria) porque `destroy()` busca el modelo con `AfipTicket::find($id)` y porque las
     * aserciones de abajo necesitan leer `deleted_at` de la base después del intento de borrado.
     *
     * @param string|null $cae
     * @return \App\Models\AfipTicket
     */
    protected function crear_afip_ticket_con_cae($cae)
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->cae          = $cae;
        $afip_ticket->cbte_tipo    = '1';
        $afip_ticket->cbte_letra   = 'A';
        $afip_ticket->punto_venta  = '1';
        $afip_ticket->cbte_numero  = '773';
        $afip_ticket->save();

        return $afip_ticket;
    }

    /**
     * El caso real de producción: un ticket con CAE autorizado no se puede borrar. Tiene que
     * responder un error (422) -nunca 200- y el registro tiene que seguir existiendo sin
     * `deleted_at` después del intento.
     *
     * @test
     */
    public function un_ticket_con_cae_autorizado_no_se_puede_borrar()
    {
        // CAE con forma real (14 dígitos), igual al que devuelve ARCA en un comprobante autorizado.
        $afip_ticket = $this->crear_afip_ticket_con_cae('70123456789012');

        $response = (new AfipTicketController())->destroy($afip_ticket->id);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            'Un AfipTicket con CAE autorizado por ARCA no se puede borrar: la respuesta tiene que '.
            'ser un error (422), nunca un 200 que deje pasar el soft-delete.'
        );

        $mensaje = $response->getData(true)['message'] ?? null;

        $this->assertNotNull(
            $mensaje,
            'La respuesta de rechazo tiene que traer un "message" explicando por qué, siguiendo la '.
            'misma convención que DevolucionesController@store.'
        );
        $this->assertStringContainsString(
            'CAE',
            $mensaje,
            'El mensaje de error tiene que mencionar el CAE: es la razón concreta del rechazo.'
        );
        $this->assertStringContainsString(
            'Nota de Crédito',
            $mensaje,
            'El mensaje tiene que indicar el camino correcto (emitir una Nota de Crédito), ya que '.
            'este método a propósito no la genera ni redirige a ningún otro flujo.'
        );

        // El intento bloqueado no puede haber tocado el registro: nada de deleted_at.
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $afip_ticket->id]);
    }

    /**
     * El ticket que NUNCA llegó a ARCA (falló antes, `cae` en NULL) tiene que poder seguir
     * borrándose exactamente igual que antes de este cambio: 200 y `deleted_at` seteado. Este
     * camino no se toca.
     *
     * @test
     */
    public function un_ticket_sin_cae_null_se_sigue_borrando_igual_que_antes()
    {
        $afip_ticket = $this->crear_afip_ticket_con_cae(null);

        $response = (new AfipTicketController())->destroy($afip_ticket->id);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Un AfipTicket sin CAE (intento de facturación que nunca llegó a ARCA) se tiene que '.
            'poder seguir borrando igual que antes: la guarda nueva no le corresponde.'
        );

        $this->assertSoftDeleted('afip_tickets', ['id' => $afip_ticket->id]);
    }

    /**
     * El mismo caso de arriba, pero con `cae` en string vacío `''` en lugar de NULL -la otra forma
     * real en que "no tiene CAE" aparece en esta tabla (ver el docblock de la clase: todo el resto
     * del código que hace esta misma pregunta trata NULL y `''` como la misma cosa). Tiene que
     * comportarse EXACTAMENTE igual que el caso NULL de arriba: 200, `deleted_at` seteado.
     *
     * Este es el caso que hace fallar a una guarda escrita como `is_null($model->cae)` a secas: con
     * `cae = ''`, `is_null('')` da `false`, así que esa guarda incorrecta trataría a este ticket
     * como si tuviera un CAE real y respondería 422 en lugar de 200 -la aserción de abajo lo
     * detecta.
     *
     * @test
     */
    public function un_ticket_con_cae_en_string_vacio_se_sigue_borrando_igual_que_con_null()
    {
        $afip_ticket = $this->crear_afip_ticket_con_cae('');

        $response = (new AfipTicketController())->destroy($afip_ticket->id);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Un cae en string vacío es "no tiene CAE", lo mismo que NULL en el resto del código '.
            '(ConsolidarFacturacionHelper, ComprobanteImputadoHelper, problemas_al_facturar): tiene '.
            'que poder seguir borrándose. Si esto da 422 en vez de 200, la guarda se escribió como '.
            'is_null($model->cae) a secas y no contempla el string vacío.'
        );

        $this->assertSoftDeleted('afip_tickets', ['id' => $afip_ticket->id]);
    }
}
