<?php 

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\AfipError;
use App\Models\AfipTicket;
use App\Models\Afip\WSAA;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSFEX;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Article;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipNotaCreditoHelper
{

    public $nota_credito;
    public $monto_minimo_para_factura_de_credito = 546737;

    function __construct($afip_ticket, $nota_credito) {
        $this->afip_ticket = $afip_ticket;
        $this->sale = $afip_ticket->sale;
        $this->nota_credito = $nota_credito;

        $this->nota_credito->load(['discounts', 'surchages']);
        
        $this->testing = true;

        if ($this->afip_ticket->afip_information->afip_ticket_production) {

            $this->testing = false;
        }
    }

    function init() {

        $this->create_afip_ticket();

        $this->notaCredito();
    }

    function notaCredito() {

        $user = UserHelper::getFullModel();

        $punto_venta = $this->afip_ticket->afip_information->punto_venta;
        $cuit_negocio = $this->afip_ticket->afip_information->cuit;


        $res = AfipSolicitarCaeHelper::get_doc_client($this->sale);
        $doc_client   = $res['doc_client'];
        $doc_type     = $res['doc_type'];

        $cbte_tipo = $this->getTipoCbte();
        Log::info('cbte_tipo para nota de credito: '.$cbte_tipo);

        if ((int)$this->afip_ticket->cbte_tipo == '19') {

            Log::info('Exportacion');
            $this->exportacion($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo);

        } else {

            $this->interno($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo);
        }

        
    }

    function exportacion($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo) {

        $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfex');
        $afip_wsaa->checkWsaa();

        $wsfex = new WSFEX([
            'testing'           => $this->testing,
            'cuit_representada' => $this->afip_ticket->afip_information->cuit,
        ]);

        $wsfex->setXmlTa(file_get_contents(TA_file));

        $res = AfipFexHelper::set_numero_comprobante($wsfex, $punto_venta, $cbte_tipo);

        if ($res['hubo_un_error']) {
            Log::info('Stop, hubo error');
            return;
        }  

        $cbte_nro = $res['numero_comprobante'];

        $pais_destino = $this->sale->client->pais_exportacion->codigo_afip;
        $idioma_cbte = 1;     // Español
        
        $moneda = 'DOL';
        // `sales.valor_dolar` es DECIMAL(20,2): Eloquent lo devuelve como string ("1234.56") y a ARCA
        // tiene que ir un número. Sin cotización queda null, como antes (no se inventa un valor).
        $moneda_cotiz = is_null($this->sale->valor_dolar) ? null : (float) $this->sale->valor_dolar;
        // Pesos es todo lo que no es dolares: una venta con moneda_id NULL o 0 (decision de Lucas,
        // 30/9/2026) sale en PES; antes, sin moneda, se mandaba como DOL con la cotizacion vacia.
        if ($this->sale->moneda_id != 2) {
            $moneda = 'PES';
            $moneda_cotiz = 1;
        }



        // Mismo criterio que interno(): un solo reloj para lo que se declara y lo que se guarda.
        $fecha_emision = Carbon::today();

        $data = [
            'Id'                    => $this->sale->id.rand(0,99999),
            'Fecha_cbte'            => $fecha_emision->format('Ymd'),
            'Cbte_Tipo'             => 21, // Nota de credito de Exportacion
            'Punto_vta'             => $this->afip_ticket->afip_information->punto_venta,
            'Cbte_nro'              => $cbte_nro,
            'Tipo_expo'             => 1,
            'pais_destino'          => $pais_destino,
            'Cliente'               => mb_convert_encoding($this->sale->client->name ?? 'Consumidor Final', 'UTF-8'),
            'Domicilio_cliente'     => mb_convert_encoding($this->sale->client->address ?? '', 'UTF-8'),
            'Id_impositivo'         => $this->sale->client->cuit ?? 'CF',
            'Moneda_Id'             => $moneda,
            'Moneda_cotiz'          => $moneda_cotiz,
            'Idioma_cbte'           => $idioma_cbte,
            'Incoterms'             => $this->sale->incoterms,
            'cuit_emisor'           => $this->afip_ticket->afip_information->cuit,
            'Permiso_existente'     => '',
        ];

        $importe_total = 0;

        foreach ($this->nota_credito->articles as $article) {
            
            $amount = (float)$article->pivot->amount;
            $price = (float)$article->pivot->price;

            if ($amount > 0) {
                
                $total_item = $price * $amount;

                $item = [];
                $item['Pro_codigo']         = $article->id;
                $item['Pro_ds']             = $article->name;
                $item['Pro_qty']            = $amount;
                $item['Pro_umed']           = 1; // FEXGetPARAM_UMed 
                $item['Pro_precio_uni']     = $price;
                $item['Pro_bonificacion']   = 0;
                $item['Pro_total_item']     = $total_item;

                $importe_total += $total_item;

                $data['items'][] = $item;
            }

        }

        foreach ($this->nota_credito->nota_credito_descriptions as $description) {
            
            $amount = 1;
            $price = (float)$description->price;

            $total_item = $price * $amount;

            $item = [];
            $item['Pro_codigo']         = $description->id;
            $item['Pro_ds']             = $description->notes;
            $item['Pro_qty']            = $amount;
            $item['Pro_umed']           = 1; // FEXGetPARAM_UMed 
            $item['Pro_precio_uni']     = $price;
            $item['Pro_bonificacion']   = 0;
            $item['Pro_total_item']     = $total_item;

            $importe_total += $total_item;

            $data['items'][] = $item;
        }

        $data['Imp_total'] = $importe_total;

        $data['CbtesAsoc'] = [
            [
                'Tipo'      => 19,
                'PtoVta'    => $this->afip_ticket->afip_information->punto_venta,
                'Nro'       => $this->afip_ticket->cbte_numero,
            ],
        ];

        $params = AfipFexHelper::get_fex_params($data);

        $result = $wsfex->FEXAuthorize($params);

        Log::info('Result nota credito Exportacion:');
        Log::info((array)$result);

        if (!$result['hubo_un_error']) {

            $autorizada = false;

            $result = $result['result'];

          //   'result' => 
          // (object) array(
          //    'FEXAuthorizeResult' => 
          //   (object) array(
          //      'FEXResultAuth' => 
          //     (object) array(
          //        'Id' => 21418,
          //        'Cuit' => 30716582899,
          //        'Cbte_tipo' => 21,
          //        'Punto_vta' => 4,
          //        'Cbte_nro' => 18,
          //        'Cae' => '75509574157500',
          //        'Fch_venc_Cae' => '20251212',
          //        'Fch_cbte' => '20251212',
          //        'Resultado' => 'A',
          //        'Reproceso' => 'N',
          //        'Motivos_Obs' => '',
          //     ),
          //      'FEXErr' => 
          //     (object) array(
          //        'ErrCode' => 0,
          //        'ErrMsg' => 'OK',
          //     ),
          //      'FEXEvents' => 
          //     (object) array(
          //        'EventCode' => 0,
          //        'EventMsg' => 'Ok',
          //     ),
          //   ),


            if (
                isset($result->FEXAuthorizeResult) 
                && isset($result->FEXAuthorizeResult->FEXResultAuth) 
                && $result->FEXAuthorizeResult->FEXResultAuth->Resultado == 'A'
            ) {

                $data = [
                    'cuit_negocio'      => $result->FEXAuthorizeResult->FEXResultAuth->Cuit,
                    'cbte_nro'          => $result->FEXAuthorizeResult->FEXResultAuth->Cbte_nro,
                    'cbte_letra'        => AfipWsHelper::getTipoLetra($result->FEXAuthorizeResult->FEXResultAuth->Cbte_tipo),
                    'cbte_tipo'         => $result->FEXAuthorizeResult->FEXResultAuth->Cbte_tipo,
                    'importe_total'     => $importe_total,
                    'moneda_id'         => $moneda,
                    'resultado'         => $result->FEXAuthorizeResult->FEXResultAuth->Resultado,
                    'concepto'          => null,
                    'cuit_cliente'      => $this->sale->client->cuit,
                    'cae'               => $result->FEXAuthorizeResult->FEXResultAuth->Cae,
                    'cae_expired_at'    => $result->FEXAuthorizeResult->FEXResultAuth->Fch_venc_Cae,
                    /*
                     * Una NC de exportacion (cbte_tipo 21) no lleva IVA: FEXAuthorize no tiene ImpIVA y el
                     * comprobante sale sin discriminar. Se guarda 0 EXPLICITO y no null a proposito: null seria
                     * "no lo medimos" y quedaria indistinguible de una NC vieja sin backfill, mientras que 0 es
                     * el numero real que le corresponde al renglon.
                     */
                    'importe_iva'        => 0,
                    'afip_fecha_emision' => $fecha_emision->format('Y-m-d'),
                ];

                $this->update_afip_ticket($data);

                $autorizada = true;
            }

            /*
             * Las observaciones y los errores se guardan DESPUES de dejar el CAE escrito, nunca antes:
             * si ARCA ya autorizo la nota, una excepcion al registrar un texto de ARCA no puede dejar
             * el comprobante sin su CAE (ver guardar_observaciones_y_errores()).
             */
            $this->guardar_observaciones_y_errores($result, $autorizada);
            
        } else {
            Log::info('HUBO UN ERROR:');
            $this->save_error($result);
        }

    }

    function interno($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo) {

        $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfe');
        $afip_wsaa->checkWsaa();

        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        $wsfe->setXmlTa(file_get_contents(TA_file));

        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);

        if (!$cbte_nro['hubo_un_error']) {

            $cbte_nro = $cbte_nro['numero_comprobante'];
        } else {

            $this->save_error($cbte_nro);
            return;
        }

        // Log::info('articulos para obtener importes:');
        // Log::info($this->nota_credito->articles);

        // Log::info('services para obtener importes:');
        // Log::info($this->nota_credito->services);

        $afip_helper = new AfipHelper($this->afip_ticket, $this->nota_credito->articles, $this->nota_credito->services, null, null, $this->nota_credito->nota_credito_descriptions, $this->nota_credito);
        $importes = $afip_helper->getImportes();

        /*
         * Un solo reloj para las dos puntas. Lo que se le declara a ARCA en CbteFch y lo que queda
         * guardado en afip_fecha_emision tienen que ser el MISMO dia, porque el renglon "IVA de notas
         * de credito emitidas" de la Posicion Fiscal fechea por afip_fecha_emision. Si se leyera
         * date() dos veces, una NC emitida a las 23:59:59 podria quedar declarada un dia y guardada
         * al siguiente, y el renglon no cerraria contra el Libro IVA por un comprobante.
         *
         * A diferencia de la factura de venta (MakeAfipTicket linea 21), la NC no tiene fecha de
         * emision elegible por el usuario: se emite siempre en el dia.
         */
        $fecha_emision = Carbon::today();
        $today = $fecha_emision->format('Ymd');
        $moneda_id = 'PES';
        /*
         * El segundo parametro es el $cbte_tipo de la NOTA DE CREDITO (3/8/13/203/208/213), el que
         * interno() recibe por parametro y manda como CbteTipo cinco lineas mas abajo, NO el de la
         * factura original ($this->afip_ticket->cbte_tipo). Es la clase de ESTE comprobante la que
         * ARCA valida contra la condicion IVA del receptor (error 10243, excluyente).
         */
        $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor($this->sale, $cbte_tipo);
        $invoice = array(
            'FeCAEReq' => array(
                'FeCabReq' => array(
                    'CantReg'      => 1,
                    'CbteTipo'     => $cbte_tipo,                   
                    'PtoVta'       => $punto_venta,
                ),
                'FeDetReq' => array(
                    'FECAEDetRequest' => array(
                        'Concepto'     => 1,                
                        'DocTipo'      => $doc_type,           
                        'DocNro'       => $doc_client,
                        'CbteDesde'    => $cbte_nro,
                        'CbteHasta'    => $cbte_nro,
                        'CbteFch'      => $today,
                        'ImpTotal'     => $importes['total'],
                        'ImpTotConc'   => $importes['neto_no_gravado'],
                        'ImpNeto'      => $importes['gravado'],
                        'ImpOpEx'      => $importes['exento'],
                        'ImpIVA'       => $importes['iva'],
                        'ImpTrib'      => 0,
                        'MonId'        => $moneda_id,
                        'MonCotiz'     => 1,
                        'Opcionales'   => $this->getOpcionales(),
                        'CondicionIVAReceptorId'    => $iva_receptor,
                        'CbtesAsoc'    => [
                            [
                                'Tipo'      => $this->afip_ticket->cbte_tipo,
                                'PtoVta'    => $this->afip_ticket->punto_venta,
                                'Nro'       => $this->afip_ticket->cbte_numero,
                                'Cuit'      => $this->afip_ticket->cuit_negocio,
                                'CbteFch'   => date_format($this->afip_ticket->created_at, 'Ymd'),
                            ],
                        ],
                    )
                )
            )
        );
        if (!is_null($this->FchVtoPago())) {
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['FchVtoPago'] = $this->FchVtoPago();
        }

        Log::info('importes:');
        Log::info($importes);

        if (
            $this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto' 
        ) {
            $ivas = [];
            foreach ($importes['ivas'] as $iva) {
                if ($iva['BaseImp'] > 0) {
                    $ivas[] = [
                        'Id'      => $iva['Id'],
                        'BaseImp' => $iva['BaseImp'],
                        'Importe' => $iva['Importe'],
                    ];
                }
            }
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['Iva'] = $ivas;
        }

        Log::info('se va a enviar a afip:');
        Log::info($invoice);

        $result = $wsfe->FECAESolicitar($invoice);
        
        Log::info('Resultado:');
        Log::info((array)$result);

        if (!$result['hubo_un_error']) {

            $result = $result['result'];

            $this->registrar_respuesta_interna($result, $cbte_nro, $importes, $moneda_id, $fecha_emision);

        } else {
            Log::info('HUBO UN ERROR:');
            $this->save_error($result);
        }

        return true;
    }

    /**
     * Lo que se hace con la respuesta de ARCA a FECAESolicitar de una nota de credito interna:
     * primero el CAE (y el total facturado de la venta), recien despues las observaciones y los
     * errores. Esta separado de interno() para poder ejercitarlo sin salir a la red.
     *
     * @param  object  $result      Arbol deserializado de la respuesta (lo que viene en ['result']).
     * @param  int     $cbte_nro    Numero de comprobante que se pidio.
     * @param  array   $importes    Importes calculados (total, iva, ...).
     * @param  string  $moneda_id
     * @param  \Carbon\Carbon  $fecha_emision
     * @return void
     */
    function registrar_respuesta_interna($result, $cbte_nro, $importes, $moneda_id, $fecha_emision) {

        $autorizada = isset($result->FECAESolicitarResult->FeCabResp) && $result->FECAESolicitarResult->FeCabResp->Resultado == 'A';

        if ($autorizada) {

            $data = [
                'cuit_negocio'      => $result->FECAESolicitarResult->FeCabResp->Cuit,
                'cbte_nro'          => $cbte_nro,
                'cbte_letra'        => AfipWsHelper::getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'cbte_tipo'         => $result->FECAESolicitarResult->FeCabResp->CbteTipo,
                'importe_total'     => $importes['total'],
                'moneda_id'         => $moneda_id,
                'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                'importe_iva'       => $importes['iva'],
                'afip_fecha_emision'=> $fecha_emision->format('Y-m-d'),
            ];

            $this->update_afip_ticket($data);

            $this->update_sale_total_facturado($data);
        }

        /*
         * Despues del CAE y no antes: ARCA ya autorizo la nota y esos datos viven solo en memoria
         * hasta que update_afip_ticket() los escribe. Antes, un fallo al guardar una observacion
         * (15/10/2026, Los Pena: error 1366 por un acento en latin1) saltaba antes de esa linea,
         * el controlador revertia la transaccion entera y la nota quedaba autorizada en ARCA sin
         * ningun registro en el sistema; reintentar emitia una segunda.
         */
        $this->guardar_observaciones_y_errores($result, $autorizada);
    }

    /**
     * Registra lo que ARCA contesto en Errors y en Observaciones, SIN poder tirar abajo una nota ya
     * autorizada.
     *
     * Si la nota salio con CAE, lo que se guarde aca es informativo y el comprobante ya existe en
     * ARCA: una excepcion al escribir un texto (encoding, columna, lo que sea) no puede propagarse,
     * porque DevolucionesController::store() revertiria la transaccion y la nota quedaria
     * autorizada en ARCA y perdida en el sistema (Los Pena, 15/10/2026). Se reporta y se sigue. Si
     * la nota NO se autorizo, lo guardado es el unico resultado util del intento y un fallo se
     * propaga como siempre.
     *
     * @param  object  $result      Arbol deserializado de la respuesta de ARCA.
     * @param  bool    $autorizada  true si ARCA devolvio Resultado = 'A'.
     * @return void
     */
    function guardar_observaciones_y_errores($result, $autorizada) {

        foreach (['checkObservations', 'checkErrors'] as $paso) {

            try {

                $this->$paso($result);

            } catch (\Throwable $e) {

                if (!$autorizada) {
                    throw $e;
                }

                Log::error('Nota de credito autorizada por ARCA: no se pudo guardar '.$paso.': '.$e->getMessage());

                report($e);
            }
        }
    }

    /**
     * Los nodos Errors/Observaciones de ARCA traen UN hijo (objeto) o VARIOS (lista); lo deja siempre
     * como lista de arrays con los textos en UTF-8. El SoapClient corre con encoding ISO-8859-1
     * (WS::__construct), asi que el arbol deserializado trae latin1 y hay que convertirlo antes de
     * guardarlo: un acento de la Resolucion General 5616 rompia el INSERT con el error 1366.
     *
     * @param  object|array|null  $hijos  Contenido de ->Obs o ->Err.
     * @return array<int,array>
     */
    function lista_de_arca_en_utf8($hijos) {

        if (is_null($hijos)) {
            return [];
        }

        $hijos = Utf8Helper::convertir_utf8($hijos);

        // Un solo hijo: viene como {Code, Msg}. Varios: viene como lista de {Code, Msg}.
        if (isset($hijos['Msg']) || isset($hijos['Code'])) {
            $hijos = [$hijos];
        }

        $lista = [];

        foreach ($hijos as $hijo) {

            $hijo = (array) $hijo;

            if (isset($hijo['Msg'])) {
                $lista[] = $hijo;
            }
        }

        return $lista;
    }

    function checkErrors($result) {
        $errors = null;
        if (isset($result->FECAESolicitarResult->Errors)) {
            $errors = $result->FECAESolicitarResult->Errors;
            $errors = isset($errors->Err) ? $errors->Err : $errors;
            foreach ($this->lista_de_arca_en_utf8($errors) as $error) {
                AfipError::create([
                    'message'   => $error['Msg'],
                    'code'      => isset($error['Code']) ? $error['Code'] : null,
                    'sale_id'   => $this->sale->id,
                    'afip_ticket_id'   => $this->created_afip_ticket->id
                ]);
            }
        }
    }

    function checkObservations($result) {
        $observations = null;
        if (isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            $observations = $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones;
            $observations = isset($observations->Obs) ? $observations->Obs : null;

            // La rama de "varias observaciones" miraba $observations['Obs'], una clave que no existe
            // en este arbol: con mas de una observacion no se guardaba ninguna.
            foreach ($this->lista_de_arca_en_utf8($observations) as $observation) {
                AfipError::create([
                    'message'   => $observation['Msg'],
                    'code'      => isset($observation['Code']) ? $observation['Code'] : null,
                    'sale_id'   => $this->sale->id,
                    'afip_ticket_id'   => $this->created_afip_ticket->id
                ]);
            }
        }
    }

    function save_error($result) {

        AfipError::create([
            'message'           => isset($result['error']) ? Utf8Helper::convertir_utf8($result['error']) : null,
            'code'              => 'Error del lado de AFIP',
            'sale_id'           => $this->sale->id,
            'afip_ticket_id'    => $this->created_afip_ticket->id,
            'request'           => isset($result['request']) ? $result['request'] : null,
            'response'          => isset($result['response']) ? $result['response'] : null,
        ]);

        $this->created_afip_ticket->update([
            'request'           => isset($result['request']) ? $result['request'] : null,
            'response'          => isset($result['response']) ? $result['response'] : null,
        ]);
    }

    function convertir_utf8($value) {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if(is_array($value)) {
            foreach($value as $key => $val) {
                $value[$key] = $this->convertir_utf8($val);
            }
            return $value;
        } else if(is_string($value)) {
            $value = $this->limpiar_cadena($value);
            $value = str_replace("\'", "", $value);
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } else {
            return $value;
        }
    }

    function limpiar_cadena($value) {
        return preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '', $value);
    }


    function create_afip_ticket() {

        $datos = [
            'afip_information_id'               => $this->afip_ticket->afip_information_id,
            'afip_tipo_comprobante_id'          => $this->afip_ticket->afip_tipo_comprobante_id,
            'iva_negocio'                       => $this->afip_ticket->afip_information->iva_condition->name,
            'punto_venta'                       => $this->afip_ticket->afip_information->punto_venta,
            'nota_credito_id'                   => $this->nota_credito->id,
            'iva_cliente'                       => !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) ? $this->sale->client->iva_condition->name : '',
            'sale_nota_credito_id'              => $this->sale->id,
            'sale_afip_ticket_id'               => $this->afip_ticket->id,
        ];

        /*
         * Una NC que ya tuvo un intento que no llego a ARCA (ticket sin CAE ni numero, tipico de un
         * rechazo o de un corte de conexion) y se vuelve a facturar desde Comprobantes reutiliza ese
         * mismo ticket: si se creara otro, `CurrentAcount::afip_ticket()` (hasOne por nota_credito_id)
         * podria seguir apuntando al fallido. En la devolucion de siempre la NC es nueva y no hay
         * ninguno: se crea como antes.
         */
        $fallido = AfipTicket::where('nota_credito_id', $this->nota_credito->id)
                        ->whereNull('cae')
                        ->whereNull('cbte_numero')
                        ->orderBy('id')
                        ->first();

        if (!is_null($fallido)) {
            $fallido->update($datos);
            $this->created_afip_ticket = $fallido;
            return;
        }

        $this->created_afip_ticket = AfipTicket::create($datos);
    }

    /**
     * true si la nota de credito ya tiene un comprobante con CAE guardado. Lo usa el controlador para
     * decidir, ante una excepcion posterior a la autorizacion, si revertir la transaccion borraria
     * el unico rastro de una nota que ya existe en ARCA.
     *
     * @param  int  $nota_credito_id
     * @return bool
     */
    static function nota_credito_con_cae($nota_credito_id) {

        return AfipTicket::where('nota_credito_id', $nota_credito_id)
                    ->whereNotNull('cae')
                    ->where('cae', '<>', '')
                    ->exists();
    }

    function update_sale_total_facturado($data) {
        Log::info('Restando total_facturado de '.$data['importe_total']);
        $this->sale->total_facturado -= (float)$data['importe_total'];
        $this->sale->save();
    }

    function update_afip_ticket($data) {
            
        $this->created_afip_ticket->update([
            'cuit_negocio'      => $data['cuit_negocio'],
            'cbte_numero'       => $data['cbte_nro'],
            'cbte_letra'        => $data['cbte_letra'],
            'cbte_tipo'         => $data['cbte_tipo'],
            'importe_total'     => $data['importe_total'],
            'moneda_id'         => $data['moneda_id'],
            'resultado'         => $data['resultado'],
            'concepto'          => $data['concepto'],
            'cuit_cliente'      => $data['cuit_cliente'],
            'cae'               => $data['cae'],
            'cae_expired_at'    => $data['cae_expired_at'],
            /*
             * Estas dos son las que hacen existir el renglon "IVA de notas de credito emitidas".
             * Hasta la mision de 1/9/2026 la NC se emitia ante ARCA con su ImpIVA (interno(), linea
             * 319) y ese numero se perdia: no quedaba en ninguna columna. Sin importe_iva no hay
             * monto que sumar; sin afip_fecha_emision no hay periodo al que imputarlo, porque toda
             * la Posicion Fiscal fechea por fecha de EMISION y no por created_at (ver
             * ContabilidadRepository::query_iva_debito(), regla 02).
             */
            'importe_iva'        => $data['importe_iva'],
            'afip_fecha_emision' => $data['afip_fecha_emision'],
        ]);
    }

    function FchVtoPago() {
        if ($this->getTipoCbte() > 200) {
            return Carbon::today()->addDays(30)->format('Ymd');
        }
        return null;
    }

    function getOpcionales() {
        if ($this->getTipoCbte() > 200) {
            return [
                [
                    'Id'    => '2101',
                    'Valor' => '0070177420000002704140',
                ],
                [
                    'Id'    => 27,
                    'Valor' => 'SCA',
                ],
            ];
        }
        return null;
    }

    function getTipoCbte() {

        $cbte_tipo = $this->afip_ticket->cbte_tipo;

        if ($cbte_tipo == '201') {
            #A
            return  203;
        } else if ($cbte_tipo == '206') {
            #B
            return  208;
        } else if ($cbte_tipo == '211') {
            #C
            return  213;
        }


        if ($cbte_tipo == '1') {
            #A
            return  3;
        } else if ($cbte_tipo == '6') {
            #B
            return  8;
        } else if ($cbte_tipo == '11') {
            #C
            return  13;
        }


        if ($cbte_tipo == '19') {
            #E
            return  21;
        } 
    }


}
