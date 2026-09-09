<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Models\Client;
use App\Models\IvaCondition;
use App\Models\Sale;
use Tests\EmpresaTestCase;

/**
 * Tests de la condicion frente al IVA del receptor (CondicionIVAReceptorId) que se le declara a
 * ARCA en cada comprobante.
 *
 * El bug real (medido en produccion el 9/9/2026 sobre los 34 frentes de empresa-api del VPS): ARCA
 * valida que ese campo sea compatible con la CLASE del comprobante (A/M/B/C) y, cuando no lo es,
 * contesta el error 10243 -"El campo Condicion IVA receptor no es valido para la clase de
 * comprobante informado"-, que es EXCLUYENTE: Resultado=R y el comprobante NO se emite.
 * CondicionIvaReceptorHelper::get_iva_receptor() derivaba el valor UNICAMENTE de la ficha del
 * cliente ($sale->client->iva_condition) y nunca miraba el tipo de comprobante, que el usuario
 * elige aparte en el modal. Dos fuentes independientes, cero conciliacion: 37 comprobantes
 * rechazados de 5 clientes (trama2 22, golonorte 3, san-cayetano 5, arfren 5, ferretotal 2).
 *
 * Los cuatro casos reales que rebotaban estan en `combinaciones_que_rebotaban_en_arca()`.
 *
 * Criterio (decidido por Lucas el 9/9/2026): manda la CLASE del comprobante, y la ficha del cliente
 * solo desempata dentro de lo que esa clase admite. Nunca se frena la emision: en el mostrador eso
 * es una venta parada.
 *
 * Como se ejercita SIN RED, mismo criterio que el resto de esta carpeta (ver docblock de
 * `4_Observacion_10245_Y_Response_Con_Byte_Invalido_Test`): nunca se llama a
 * `MakeAfipTicket::make_afip_ticket()` ni a `AfipWsController` (dispararian contra ARCA de verdad y
 * necesitarian TA_file + WSDL reales). Se llama al metodo de produccion `get_iva_receptor()`
 * DIRECTO: es estatico y lo unico que lee de la venta es `$sale->client->iva_condition->name`.
 *
 * La venta y el cliente se arman EN MEMORIA (`new Sale()` + `setRelation()`, sin `save()`: no se
 * escribe una sola fila), pero la `IvaCondition` es el MODELO REAL leido de la base de testing, no
 * un doble: asi el test tambien se entera si el `IvaConditionSeeder` cambia los nombres, que es de
 * donde salen las cuatro fichas posibles.
 *
 * @group facturacion
 * @group afip
 */
class Condicion_Iva_Receptor_Por_Clase_De_Comprobante_Test extends EmpresaTestCase
{
    /** Ficha del cliente cargada, pero sin condicion IVA elegida. */
    const FICHA_SIN_CONDICION = '__cliente_sin_condicion_iva__';

    /** Venta sin cliente (mostrador). */
    const FICHA_SIN_CLIENTE = '__venta_sin_cliente__';

    /**
     * Los cuatro nombres del `IvaConditionSeeder`. Escritos a mano a proposito: si el test los
     * leyera del seeder, no mediria nada. `condiciones_de_la_base()` verifica que la base
     * realmente tenga estos cuatro y ningun otro.
     *
     * @var string[]
     */
    const FICHAS_DEL_SEEDER = ['Responsable inscripto', 'Monotributista', 'Consumidor final', 'Exento'];

    /**
     * Codigos de comprobante que el sistema EMITE hoy, con su clase segun la tabla oficial de ARCA
     * (manual del desarrollador COMPG v4.0). Las facturas salen del `AfipTipoComprobanteSeeder`
     * (1/6/11/51/201/206/211/19) y las notas de credito de
     * `AfipNotaCreditoHelper::getTipoCbte()` (3/8/13/203/208/213/21).
     *
     * La clase E (19 y 21, exportacion) NO esta: ese circuito va por `AfipFexHelper` (FEXAuthorize)
     * y ni siquiera manda este campo. Se cubre aparte, en `clase_e_y_codigo_desconocido_*`.
     *
     * @var array<int,string>
     */
    const CODIGOS_QUE_EMITE_EL_SISTEMA = [
        1   => 'A', // Factura A
        201 => 'A', // FCE A
        3   => 'A', // Nota de credito A
        203 => 'A', // Nota de credito FCE A
        51  => 'M', // Factura M
        6   => 'B', // Factura B
        206 => 'B', // FCE B
        8   => 'B', // Nota de credito B
        208 => 'B', // Nota de credito FCE B
        11  => 'C', // Factura C
        211 => 'C', // FCE C
        13  => 'C', // Nota de credito C
        213 => 'C', // Nota de credito FCE C
    ];

    /**
     * Ids de condicion IVA del receptor que ARCA acepta por clase de comprobante. Copiados a mano
     * del manual oficial (COMPG v4.0), NO leidos de `CondicionIvaReceptorHelper`: el codigo bajo
     * prueba no puede ser su propio oraculo.
     *
     * @return array<string,int[]>
     */
    protected function ids_que_arca_acepta_por_clase()
    {
        return [
            'A' => [1, 6, 13, 16],
            'M' => [1, 6, 13, 16],
            'B' => [4, 5, 7, 8, 9, 10, 15],
            'C' => [1, 6, 13, 16, 4, 5, 7, 8, 9, 10, 15],
        ];
    }

    /**
     * Venta EN MEMORIA (nunca se guarda) con la ficha pedida.
     *
     * @param string $ficha Nombre de la `iva_condition`, o una de las dos constantes FICHA_*.
     * @return \App\Models\Sale
     */
    protected function venta_con_ficha($ficha)
    {
        $sale = new Sale();

        if ($ficha === self::FICHA_SIN_CLIENTE) {

            // setRelation con null deja la relacion CARGADA en null: Eloquent no sale a la base.
            $sale->setRelation('client', null);

            return $sale;
        }

        $client = new Client();

        if ($ficha === self::FICHA_SIN_CONDICION) {

            $client->setRelation('iva_condition', null);
        } else {

            $iva_condition = IvaCondition::where('name', $ficha)->first();

            $this->assertNotNull(
                $iva_condition,
                'Falta la iva_condition "'.$ficha.'" en la base de testing (la siembra el IvaConditionSeeder).'
            );

            $client->setRelation('iva_condition', $iva_condition);
        }

        $sale->setRelation('client', $client);

        return $sale;
    }

    /**
     * Todas las fichas que un cliente puede tener, incluidas las dos formas de "vacia".
     *
     * @return string[]
     */
    protected function todas_las_fichas_posibles()
    {
        return array_merge(self::FICHAS_DEL_SEEDER, [self::FICHA_SIN_CONDICION, self::FICHA_SIN_CLIENTE]);
    }

    /**
     * Las cuatro combinaciones reales que ARCA rechazaba con el 10243, sacadas de produccion el
     * 9/9/2026. Formato: [descripcion, ficha, cbte_tipo, valor esperado, valor que se mandaba antes].
     *
     * @return array<array>
     */
    protected function combinaciones_que_rebotaban_en_arca()
    {
        return [
            ['golonorte 28/7/2026, Factura A a un Consumidor final', 'Consumidor final', 1, 1, 5],
            ['trama2 14/7/2026, Factura A a un cliente Exento', 'Exento', 1, 1, 4],
            ['Factura A a un cliente sin condicion IVA cargada', self::FICHA_SIN_CONDICION, 1, 1, 5],
            ['san-cayetano 14/4/2026 y arfren 6/5/2026, Factura B a un Responsable inscripto', 'Responsable inscripto', 6, 5, 1],
        ];
    }

    /**
     * @test
     */
    public function las_cuatro_combinaciones_que_rebotaban_en_arca_ahora_mandan_un_valor_valido()
    {
        foreach ($this->combinaciones_que_rebotaban_en_arca() as $caso) {

            list($descripcion, $ficha, $cbte_tipo, $esperado, $valor_viejo) = $caso;

            $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor(
                $this->venta_con_ficha($ficha),
                $cbte_tipo
            );

            $this->assertSame(
                $esperado,
                $iva_receptor,
                'Caso real de produccion ('.$descripcion.'): con el comprobante '.$cbte_tipo.
                ' hay que mandar '.$esperado.'. Antes se mandaba '.$valor_viejo.
                ' -derivado solo de la ficha del cliente- y ARCA lo rechazaba con el error 10243 '.
                '(excluyente: Resultado=R, el comprobante no se emite).'
            );
        }
    }

    /**
     * Tabla de decision completa: las 6 fichas posibles contra las 4 clases, sobre los codigos que
     * el sistema emite. Es la tabla que decidio Lucas el 9/9/2026.
     *
     * @return array<string,array>
     */
    public function tabla_de_decision()
    {
        $casos = [];

        /*
         * [ficha => [clase => valor esperado]]. La clase A y la M comparten fila porque ARCA les
         * acepta exactamente las mismas condiciones (1/6/13/16).
         */
        $tabla = [
            'Responsable inscripto'    => ['A' => 1, 'M' => 1, 'B' => 5, 'C' => 1],
            'Monotributista'           => ['A' => 6, 'M' => 6, 'B' => 5, 'C' => 6],
            'Consumidor final'         => ['A' => 1, 'M' => 1, 'B' => 5, 'C' => 5],
            'Exento'                   => ['A' => 1, 'M' => 1, 'B' => 4, 'C' => 4],
            self::FICHA_SIN_CONDICION  => ['A' => 1, 'M' => 1, 'B' => 5, 'C' => 5],
            self::FICHA_SIN_CLIENTE    => ['A' => 1, 'M' => 1, 'B' => 5, 'C' => 5],
        ];

        foreach ($tabla as $ficha => $por_clase) {

            foreach (self::CODIGOS_QUE_EMITE_EL_SISTEMA as $cbte_tipo => $clase) {

                $nombre = 'ficha "'.$ficha.'" + comprobante '.$cbte_tipo.' (clase '.$clase.')';

                $casos[$nombre] = [$ficha, $cbte_tipo, $por_clase[$clase]];
            }
        }

        return $casos;
    }

    /**
     * @test
     * @dataProvider tabla_de_decision
     *
     * @param string $ficha
     * @param int $cbte_tipo
     * @param int $esperado
     */
    public function la_tabla_de_decision_completa_se_respeta($ficha, $cbte_tipo, $esperado)
    {
        $this->assertSame(
            $esperado,
            CondicionIvaReceptorHelper::get_iva_receptor($this->venta_con_ficha($ficha), $cbte_tipo),
            'La tabla de decision del 9/9/2026 dice que con esta ficha y este comprobante hay que '.
            'mandar '.$esperado.'.'
        );
    }

    /**
     * 🔴 El test que hace que esto no vuelva: para TODO codigo que el sistema emite por TODA ficha
     * posible, el valor que se manda tiene que estar en la lista de validos de esa clase segun
     * ARCA. No mira "cual" valor, mira que NUNCA pueda salir uno invalido -que es lo unico que
     * dispara el 10243-.
     *
     * @test
     */
    public function ningun_codigo_por_ninguna_ficha_puede_dar_un_valor_invalido_para_su_clase()
    {
        $validos_por_clase = $this->ids_que_arca_acepta_por_clase();

        $combinaciones = 0;

        foreach (self::CODIGOS_QUE_EMITE_EL_SISTEMA as $cbte_tipo => $clase) {

            foreach ($this->todas_las_fichas_posibles() as $ficha) {

                $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor(
                    $this->venta_con_ficha($ficha),
                    $cbte_tipo
                );

                $this->assertContains(
                    $iva_receptor,
                    $validos_por_clase[$clase],
                    'INVARIANTE: el comprobante '.$cbte_tipo.' es de clase '.$clase.', y ARCA solo le '.
                    'acepta las condiciones ['.implode(', ', $validos_por_clase[$clase]).']. Con la ficha "'.
                    $ficha.'" se estaria mandando '.$iva_receptor.', y ARCA lo rechazaria con el error '.
                    '10243 (excluyente: el comprobante no se emite).'
                );

                $combinaciones++;
            }
        }

        $this->assertSame(
            count(self::CODIGOS_QUE_EMITE_EL_SISTEMA) * count($this->todas_las_fichas_posibles()),
            $combinaciones,
            'El invariante tiene que haber recorrido todos los codigos por todas las fichas.'
        );
    }

    /**
     * Las combinaciones que YA andaban bien antes del cambio no se pueden romper.
     *
     * @test
     */
    public function las_combinaciones_que_ya_andaban_siguen_igual()
    {
        $casos = [
            ['Responsable inscripto', 1, 1], // Factura A a un RI
            ['Consumidor final', 6, 5],      // Factura B a un consumidor final
            ['Exento', 6, 4],                // Factura B a un exento
            ['Monotributista', 1, 6],        // Factura A a un monotributista
            ['Monotributista', 11, 6],       // Factura C a un monotributista
        ];

        foreach ($casos as $caso) {

            list($ficha, $cbte_tipo, $esperado) = $caso;

            $this->assertSame(
                $esperado,
                CondicionIvaReceptorHelper::get_iva_receptor($this->venta_con_ficha($ficha), $cbte_tipo),
                'Esta combinacion ya andaba bien con el codigo viejo (ficha "'.$ficha.'" + comprobante '.
                $cbte_tipo.'): el cambio no la puede mover.'
            );
        }
    }

    /**
     * @test
     */
    public function la_clase_de_cada_codigo_de_comprobante_es_la_de_la_tabla_de_arca()
    {
        $esperadas = self::CODIGOS_QUE_EMITE_EL_SISTEMA + [
            2   => 'A', 202 => 'A',
            52  => 'M', 53  => 'M',
            7   => 'B', 207 => 'B',
            12  => 'C', 212 => 'C',
            19  => 'E', 20  => 'E', 21 => 'E',
        ];

        foreach ($esperadas as $cbte_tipo => $clase) {

            $this->assertSame(
                $clase,
                CondicionIvaReceptorHelper::clase_de_comprobante($cbte_tipo),
                'El comprobante '.$cbte_tipo.' es de clase '.$clase.' segun la tabla oficial de ARCA.'
            );
        }

        // El codigo tambien viaja como string segun el llamador (getTipoCbte de la nota de credito
        // compara contra '1', '6', '11' con comillas): tiene que resolver igual.
        $this->assertSame(
            'B',
            CondicionIvaReceptorHelper::clase_de_comprobante('6'),
            'El codigo de comprobante llega a veces como string: tiene que resolver la misma clase.'
        );
    }

    /**
     * @test
     */
    public function un_codigo_desconocido_o_no_numerico_no_tiene_clase()
    {
        foreach ([null, 0, 999, 'factura', ''] as $cbte_tipo) {

            $this->assertNull(
                CondicionIvaReceptorHelper::clase_de_comprobante($cbte_tipo),
                'Un codigo de comprobante que no esta en la tabla de ARCA no puede inventar una clase.'
            );
        }
    }

    /**
     * Clase E (exportacion), codigo desconocido y llamador sin el segundo parametro: los tres caen
     * al comportamiento historico -lo que diga la ficha-. Es el fallback seguro: no arregla nada,
     * pero tampoco empeora lo que ya se mandaba. La exportacion va por `AfipFexHelper`
     * (FEXAuthorize), que ni siquiera manda este campo.
     *
     * @test
     */
    public function la_clase_e_el_codigo_desconocido_y_el_llamador_sin_parametro_mantienen_lo_historico()
    {
        $historico = [
            'Responsable inscripto'   => 1,
            'Monotributista'          => 6,
            'Consumidor final'        => 5,
            'Exento'                  => 4,
            self::FICHA_SIN_CONDICION => 5,
            self::FICHA_SIN_CLIENTE   => 5,
        ];

        foreach ($historico as $ficha => $esperado) {

            $sale = $this->venta_con_ficha($ficha);

            $this->assertSame(
                $esperado,
                CondicionIvaReceptorHelper::get_iva_receptor($sale),
                'Sin segundo parametro (llamador viejo) tiene que devolver exactamente lo de antes: '.
                'la firma nueva no puede romper a nadie.'
            );

            $this->assertSame(
                $esperado,
                CondicionIvaReceptorHelper::get_iva_receptor($sale, 19),
                'Clase E (factura de exportacion, codigo 19): ese circuito va por AfipFexHelper y ni '.
                'manda este campo, asi que se mantiene el comportamiento historico.'
            );

            $this->assertSame(
                $esperado,
                CondicionIvaReceptorHelper::get_iva_receptor($sale, 21),
                'Clase E (nota de credito de exportacion, codigo 21): mismo circuito, mismo criterio.'
            );

            $this->assertSame(
                $esperado,
                CondicionIvaReceptorHelper::get_iva_receptor($sale, 4321),
                'Codigo de comprobante desconocido: fallback al comportamiento historico, nunca una '.
                'clase inventada.'
            );
        }
    }

    /**
     * Una nota de credito se valida por SU propia clase, no por la de la factura que anula. La NC
     * de una Factura A es el codigo 3 (clase A) y la de una Factura B es el 8 (clase B), tal como
     * los devuelve `AfipNotaCreditoHelper::getTipoCbte()`.
     *
     * @test
     */
    public function la_nota_de_credito_se_resuelve_por_su_propia_clase()
    {
        $this->assertSame(
            'A',
            CondicionIvaReceptorHelper::clase_de_comprobante(3),
            'La nota de credito A (codigo 3) es de clase A.'
        );

        $this->assertSame(
            'B',
            CondicionIvaReceptorHelper::clase_de_comprobante(8),
            'La nota de credito B (codigo 8) es de clase B.'
        );

        $this->assertSame(
            1,
            CondicionIvaReceptorHelper::get_iva_receptor($this->venta_con_ficha('Consumidor final'), 3),
            'Nota de credito A (codigo 3) a un consumidor final: la clase A no acepta el 5, va 1.'
        );

        $this->assertSame(
            5,
            CondicionIvaReceptorHelper::get_iva_receptor($this->venta_con_ficha('Responsable inscripto'), 8),
            'Nota de credito B (codigo 8) a un responsable inscripto: la clase B no acepta el 1, va 5.'
        );
    }

    /**
     * Las cuatro fichas posibles salen del `IvaConditionSeeder` y de ningun otro lado. Si manana
     * alguien agrega una quinta, este test lo denuncia: la tabla de decision de arriba habria
     * quedado incompleta.
     *
     * @test
     */
    public function la_base_tiene_exactamente_las_cuatro_condiciones_iva_del_seeder()
    {
        $nombres = IvaCondition::orderBy('id')->pluck('name')->all();

        $this->assertSame(
            self::FICHAS_DEL_SEEDER,
            $nombres,
            'Las condiciones IVA de la base cambiaron respecto del IvaConditionSeeder. La tabla de '.
            'decision de CondicionIvaReceptorHelper::get_iva_receptor() esta escrita sobre esas '.
            'cuatro: si hay una quinta, hay que decidir a que valor mapea en cada clase.'
        );
    }
}
