<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSSRConstanciaInscripcion;
use Illuminate\Support\Facades\Log;

class CondicionIvaReceptorHelper {

    function get_data() {

        define ('TA_file', public_path().'/afip/wsaa/wsfe/TA.xml'); 

        $testing = true;

        $afip_wsaa = new AfipWSAAHelper($testing, 'wsfe');
        
        $afip_wsaa->checkWsaa();


        // $ws = new WSSRConstanciaInscripcion(['testing'=> $testing, 'cuit_representada' => '20423548984', 'for_constancia_de_inscripcion' => false]);


        $ws = new WSFE([
            'testing'=> $testing, 
            'cuit_representada' => '20381712010'
        ]);

        $ws->setXmlTa(file_get_contents(TA_file));



        $xmlString = file_get_contents(TA_file); // Aquí coloca el XML completo como cadena

        // Convertir el XML en un objeto SimpleXMLElement
        $xml = simplexml_load_string($xmlString);

        // Acceder a los valores de <token> y <sign>
        $token = (string) $xml->credentials->token;
        $sign = (string) $xml->credentials->sign;


        $res = $ws->FEParamGetCondicionIvaReceptor([
            // 'Auth' => [
            //     'Token' => $token,
            //     'Sign'  => $sign,
            //     'cuit'  => '20423548984', 
            // ],
        ]);

        Log::info('FEParamGetCondicionIvaReceptor:');
        Log::info($res);
    }

    /**
     * Tabla oficial de ARCA (RG 5616) de condiciones frente al IVA del receptor, con las clases de
     * comprobante en las que cada una es VALIDA. Es la misma tabla que devuelve
     * FEParamGetCondicionIvaReceptor, y coincide con el manual del desarrollador ARCA COMPG v4.0.
     *
     * Hasta el 9/9/2026 esta tabla vivia como codigo MUERTO debajo del `return` de
     * get_iva_receptor(): estaba escrita pero no la leia nadie, y el valor se derivaba unicamente
     * de la ficha del cliente. Ahora es la fuente real de la decision (ver ids_validos_para_clase).
     */
    const CONDICIONES_IVA_RECEPTOR = [
        [
            "Id" => 1,
            "Desc" => "IVA Responsable Inscripto",
            "Cmp_Clase" => "A/M/C"
        ],
        [
            "Id" => 6,
            "Desc" => "Responsable Monotributo",
            "Cmp_Clase" => "A/M/C"
        ],
        [
            "Id" => 13,
            "Desc" => "Monotributista Social",
            "Cmp_Clase" => "A/M/C"
        ],
        [
            "Id" => 16,
            "Desc" => "Monotributo Trabajador Independiente Promovido",
            "Cmp_Clase" => "A/M/C"
        ],
        [
            "Id" => 4,
            "Desc" => "IVA Sujeto Exento",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 5,
            "Desc" => "Consumidor Final",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 7,
            "Desc" => "Sujeto No Categorizado",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 8,
            "Desc" => "Proveedor del Exterior",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 9,
            "Desc" => "Cliente del Exterior",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 10,
            "Desc" => "IVA Liberado – Ley N° 19.640",
            "Cmp_Clase" => "B/C"
        ],
        [
            "Id" => 15,
            "Desc" => "IVA No Alcanzado",
            "Cmp_Clase" => "B/C"
        ],
    ];

    /**
     * Clase de comprobante de cada codigo de ARCA. Es el mapa que faltaba: el tipo de comprobante
     * lo elige el usuario a mano en el modal (AfipWsfeHelper::getTipoCbte lee
     * afip_tipo_comprobante_id) y hasta ahora nadie lo cruzaba con la condicion IVA del receptor.
     *
     * Estan los codigos que el sistema emite hoy (1/6/11/51/201/206/211/19 y sus notas de credito
     * 3/8/13/203/208/213/21) y tambien los que no emite (notas de debito, nota de credito M), por
     * robustez: si manana se habilita alguno, la clase ya sale bien sin tocar nada.
     */
    const CLASE_POR_CODIGO_DE_COMPROBANTE = [
        1   => 'A', // Factura A
        2   => 'A', // Nota de debito A
        3   => 'A', // Nota de credito A
        201 => 'A', // Factura de credito electronica MiPyMEs (FCE) A
        202 => 'A', // Nota de debito FCE A
        203 => 'A', // Nota de credito FCE A

        51  => 'M', // Factura M
        52  => 'M', // Nota de debito M
        53  => 'M', // Nota de credito M

        6   => 'B', // Factura B
        7   => 'B', // Nota de debito B
        8   => 'B', // Nota de credito B
        206 => 'B', // FCE B
        207 => 'B', // Nota de debito FCE B
        208 => 'B', // Nota de credito FCE B

        11  => 'C', // Factura C
        12  => 'C', // Nota de debito C
        13  => 'C', // Nota de credito C
        211 => 'C', // FCE C
        212 => 'C', // Nota de debito FCE C
        213 => 'C', // Nota de credito FCE C

        19  => 'E', // Factura de exportacion
        20  => 'E', // Nota de debito por operaciones con el exterior
        21  => 'E', // Nota de credito por operaciones con el exterior
    ];

    /**
     * Valor que se manda cuando lo que dice la ficha del cliente NO es valido para la clase del
     * comprobante que se esta emitiendo. Es el criterio que eligio Lucas el 9/9/2026: manda la
     * clase, y la ficha solo desempata dentro de lo que esa clase admite.
     *
     * La clase E (exportacion) no esta a proposito: ese circuito va por AfipFexHelper y ni siquiera
     * manda este campo.
     */
    const DEFAULT_POR_CLASE = [
        'A' => 1, // IVA Responsable Inscripto
        'M' => 1, // IVA Responsable Inscripto
        'B' => 5, // Consumidor Final
        'C' => 5, // Consumidor Final
    ];

    /**
     * Condicion frente al IVA del receptor (CondicionIVAReceptorId) que se le declara a ARCA.
     *
     * El problema que resuelve: ARCA valida que este valor sea compatible con la CLASE del
     * comprobante, y cuando no lo es contesta el error 10243 ("El campo Condicion IVA receptor no
     * es valido para la clase de comprobante informado"), que es EXCLUYENTE: Resultado=R y el
     * comprobante NO se emite. Hasta el 9/9/2026 este metodo derivaba el valor unicamente de la
     * ficha del cliente y nunca miraba el tipo de comprobante, que el usuario elige aparte en el
     * modal: dos fuentes independientes y cero conciliacion. Medido sobre los 34 frentes del VPS,
     * eso venia rebotando 37 comprobantes de 5 clientes (trama2, golonorte, san-cayetano, arfren,
     * ferretotal).
     *
     * Criterio (decidido por Lucas el 9/9/2026): manda la clase del comprobante, y la ficha del
     * cliente solo desempata DENTRO de lo que esa clase admite. Tabla de decision resultante
     * (la fila "-" es ficha vacia, sin condicion cargada, o venta sin cliente):
     *
     *   Ficha del cliente     | Clase A/M | Clase B | Clase C
     *   ----------------------|-----------|---------|--------
     *   Responsable inscripto |     1     |    5    |    1
     *   Monotributista        |     6     |    5    |    6
     *   Consumidor final      |     1     |    5    |    5
     *   Exento                |     1     |    4    |    4
     *   -                     |     1     |    5    |    5
     *
     * NUNCA se frena la emision ni se le pide nada al usuario: en el mostrador eso es una venta
     * parada. El valor devuelto es SIEMPRE valido para la clase, sin excepcion.
     *
     * @param \App\Models\Sale|null $sale Venta que se esta facturando (de ahi sale la ficha).
     * @param int|string|null $cbte_tipo Codigo de comprobante de ARCA que se va a mandar como
     *                                   CbteTipo en ESTE comprobante (en una nota de credito, el
     *                                   de la NC: 3/8/13/..., no el de la factura original). Con
     *                                   null se mantiene el comportamiento historico, para no
     *                                   romper ningun llamador.
     * @return int Id de condicion IVA del receptor.
     */
    static function get_iva_receptor($sale, $cbte_tipo = null) {

        /** Lo que dice la ficha del cliente (5, consumidor final, si no hay ficha ni cliente). */
        $segun_la_ficha = self::condicion_segun_la_ficha($sale);

        $clase = self::clase_de_comprobante($cbte_tipo);

        /*
         * Sin tipo de comprobante (llamador que no lo pasa), con un codigo desconocido, o con
         * clase E (exportacion, que va por AfipFexHelper y ni manda este campo): se mantiene el
         * comportamiento historico. Es el fallback seguro, nunca empeora lo que ya se mandaba.
         */
        if (is_null($clase) || !isset(self::DEFAULT_POR_CLASE[$clase])) {

            return $segun_la_ficha;
        }

        // Si lo que dice la ficha ya es valido para esta clase, se manda tal cual.
        if (in_array($segun_la_ficha, self::ids_validos_para_clase($clase), true)) {

            return $segun_la_ficha;
        }

        // Si no lo es, manda la clase. Esta es la rama que evita el 10243.
        return self::DEFAULT_POR_CLASE[$clase];
    }

    /**
     * Clase de comprobante ('A', 'M', 'B', 'C' o 'E') de un codigo de ARCA.
     *
     * @param int|string|null $cbte_tipo Codigo de comprobante de ARCA (1, 6, 11, 3, 8, 201, ...).
     * @return string|null La clase, o null si el codigo es nulo, no numerico o desconocido.
     */
    static function clase_de_comprobante($cbte_tipo) {

        if (is_null($cbte_tipo) || !is_numeric($cbte_tipo)) {

            return null;
        }

        /** El codigo viaja a veces como string ('6') y a veces como int, segun el llamador. */
        $codigo = (int) $cbte_tipo;

        if (!isset(self::CLASE_POR_CODIGO_DE_COMPROBANTE[$codigo])) {

            return null;
        }

        return self::CLASE_POR_CODIGO_DE_COMPROBANTE[$codigo];
    }

    /**
     * Ids de condicion IVA del receptor que ARCA acepta para una clase de comprobante, sacados de
     * la tabla oficial (CONDICIONES_IVA_RECEPTOR), no de una lista escrita aparte.
     *
     * @param string $clase 'A', 'M', 'B' o 'C'.
     * @return int[]
     */
    static function ids_validos_para_clase($clase) {

        $ids = [];

        foreach (self::CONDICIONES_IVA_RECEPTOR as $condicion) {

            if (in_array($clase, explode('/', $condicion['Cmp_Clase']), true)) {

                $ids[] = $condicion['Id'];
            }
        }

        return $ids;
    }

    /**
     * Condicion IVA del receptor segun la ficha del cliente. Es exactamente lo que este helper
     * devolvia SIEMPRE hasta el 9/9/2026, y sigue siendo el punto de partida: la clase del
     * comprobante solo lo corrige cuando ese valor no es compatible con ella.
     *
     * Los cuatro nombres posibles son los del IvaConditionSeeder; no hay otros.
     *
     * @param \App\Models\Sale|null $sale
     * @return int
     */
    static function condicion_segun_la_ficha($sale) {

        $iva_receptor = 5; //consumidor final

        if (!is_null($sale) && $sale->client) {

            $iva_condition = $sale->client->iva_condition;

            if (!is_null($iva_condition)) {

                if ($iva_condition->name == 'Responsable inscripto') {

                    $iva_receptor = 1;
                } else if ($iva_condition->name == 'Monotributista') {

                    $iva_receptor = 6;
                } else if ($iva_condition->name == 'Consumidor final') {

                } else if ($iva_condition->name == 'Exento') {

                    $iva_receptor = 4;
                }
            }
        }

        return $iva_receptor;
    }


}
