<?php

namespace Tests\Feature\Iva;

use App\Http\Controllers\AfipController;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\Afip\TicketInfoHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\IvaCondition;
use App\Models\NotaCreditoDescription;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Espia de FPDF: se queda con el texto de cada `Cell()` en el orden en que se dibuja.
 *
 * Por que existe: los dos bloques que esta clase verifica —`AfipPdfHelper::print_footer_importes_block()`
 * y `TicketInfoHelper::print_iva_and_totals()`— escriben en un FPDF de verdad, y un FPDF de verdad
 * arrastra fuentes, el QR de ARCA (imagen y red) y un `Output()` que mata el proceso. Los dos bloques
 * usan SOLO `Cell()`, `SetFont()`, `SetFillColor()`, `x` e `y`, asi que con esto alcanza y no se toca
 * nada de eso.
 *
 * 🔴 Se guarda el texto CRUDO de cada celda, sin normalizar: lo que se esta midiendo es exactamente
 * lo que sale impreso en el papel.
 */
class EspiaDePdf
{
    /** @var float Posicion horizontal, que los bloques leen y escriben. */
    public $x = 0;

    /** @var float Posicion vertical, idem. */
    public $y = 0;

    /** @var array<int, string> Texto de cada celda dibujada, en orden. */
    public $renglones = [];

    /**
     * @param float $w Ancho de la celda (se ignora).
     * @param float $h Alto de la celda (se ignora).
     * @param string $txt Texto de la celda: lo unico que interesa.
     * @return void
     */
    public function Cell($w = 0, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false)
    {
        $this->renglones[] = (string) $txt;
    }

    /**
     * @return void
     */
    public function SetFont($family, $style = '', $size = 0)
    {
    }

    /**
     * @return void
     */
    public function SetFillColor($r, $g = null, $b = null)
    {
    }
}

/**
 * La clave del bucket de IVA y la etiqueta que se imprime son DOS COSAS DISTINTAS, y las dos
 * fuentes de importes —recalculo y snapshot de autorizacion— tienen que emitir la MISMA clave.
 *
 * Por que existe este archivo: hasta esta mision el sistema convivia con dos convenciones de clave
 * para el mismo arreglo de alicuotas. El recalculo (`AfipImportesCalculator::default_ivas()`) emitia
 * `'10'` para 10,5 %; el snapshot (la traduccion de Id de ARCA a clave, hoy
 * `AfipImportesResolver::clave_de_id()`) emitia `'10.5'`; y las
 * lineas de descripcion libre armaban la clave con `(string) (float) $percentage`, o sea `'10.5'`,
 * cayendo en un bucket paralelo con `Id => 0`. Cada consumidor andaba bien con UN productor y mal
 * con el otro, y desde ningun archivo se veia el problema completo:
 *
 *  - El Libro IVA Ventas lee `['ivas']['10']`: con snapshot no encontraba el renglon.
 *  - Los PDF recorrian una lista de etiquetas (`'10.5'`) usandola tambien como clave de lectura:
 *    el papel decia "IVA 10.5%: $0" con 105 pesos de IVA adentro; o, del otro lado, "IVA 10%".
 *  - El TXT de alicuotas que el contador le sube a ARCA sacaba un renglon con codigo `0000`.
 *
 * 🔴 Cada test de esta clase compara las DOS fuentes, o el numero contra un literal calculado a
 * mano. Un verde que tambien pasaria con el codigo viejo no probaria nada.
 *
 * 🔴 No toca la red. No se llama `interno()` ni `make_afip_ticket()`, y los PDF se dibujan sobre el
 * espia de arriba, nunca sobre un FPDF real.
 *
 * Rango de fechas propio: MARZO DE 2014. Importa de verdad y no es cosmetico: `exportVentas()` y
 * `exportAlicuotasTxt()` filtran SOLO por `created_at`, sin scope por usuario, asi que cualquier
 * comprobante de otro test que cayera en el mismo mes se colaria en el archivo. El resto de la
 * suite usa de julio de 2015 en adelante.
 *
 * @group iva-claves-alicuota
 */
class Claves_De_Alicuota_Unificadas_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca assertSame sobre floats, y nunca
     * `assertEquals` con un cuarto argumento: PHPUnit 9.6 lo descarta en silencio).
     */
    const DELTA = 0.01;

    /**
     * Fecha de todo lo que siembra esta clase. Ver el bloque de arriba: marzo de 2014 esta libre.
     */
    const FECHA = '2014-03-10 10:00:00';

    /**
     * Periodo con el que se piden el libro y los dos TXT (formato Y-m, que es lo que reciben).
     */
    const PERIODO = '2014-03';

    /**
     * Ids de lo sembrado, para borrarlo en el tearDown en orden inverso al de creacion.
     *
     * @var array<string, array<int, int>>
     */
    protected $sembrado = [
        'afip_tickets'              => [],
        'nota_credito_descriptions' => [],
        'current_acounts'           => [],
        'sales'                     => [],
        'afip_information'          => [],
    ];

    /**
     * Archivos que dejaron `exportAlicuotasTxt()` / `exportVentas()` en el disco `local`.
     *
     * @var array<int, string>
     */
    protected $archivos_generados = [];

    /**
     * Borra lo sembrado en orden inverso al de creacion, corte el test donde corte.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_generados as $archivo) {
            if (Storage::disk('local')->exists($archivo)) {
                Storage::disk('local')->delete($archivo);
            }
        }

        if (count($this->sembrado['afip_tickets'])) {
            // forceDelete y no delete: AfipTicket usa SoftDeletes y un borrado normal dejaria la
            // fila viva con deleted_at puesto, dentro del rango de fechas de esta clase.
            AfipTicket::whereIn('id', $this->sembrado['afip_tickets'])->forceDelete();
        }

        if (count($this->sembrado['nota_credito_descriptions'])) {
            NotaCreditoDescription::whereIn('id', $this->sembrado['nota_credito_descriptions'])->delete();
        }

        foreach ($this->sembrado['current_acounts'] as $current_acount_id) {
            $current_acount = CurrentAcount::find($current_acount_id);

            if (is_null($current_acount)) {
                continue;
            }

            $current_acount->articles()->detach();
            $current_acount->services()->detach();
            $current_acount->discounts()->detach();
            $current_acount->surchages()->detach();
            $current_acount->delete();
        }

        foreach ($this->sembrado['sales'] as $sale_id) {
            $sale = Sale::find($sale_id);

            if (is_null($sale)) {
                continue;
            }

            $sale->articles()->detach();
            $sale->forceDelete();
        }

        if (count($this->sembrado['afip_information'])) {
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------------------------
    // Montaje del fixture
    // -----------------------------------------------------------------------------------------

    /**
     * Usuario duenio del fixture (el mismo que autentica `EmpresaTestCase::setUp()`).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Configuracion fiscal propia del test, Responsable inscripto.
     *
     * Se crea la propia aunque el fixture ya traiga una: `AfipImportesCalculator::calculate()` solo
     * desglosa por alicuota si el emisor es Responsable inscripto, y toda esta clase mide justamente
     * el desglose. Si maniana el seeder le cambiara la condicion al fixture, estos tests medirian
     * otra rama sin enterarse.
     *
     * @return \App\Models\AfipInformation
     */
    protected function configuracion_fiscal_responsable_inscripto()
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva_condition)) {
            $this->fail(
                'No existe la condicion de IVA "Responsable inscripto" en la base de testing. '.
                'AfipImportesCalculator::calculate() la lee por NOMBRE para decidir si desglosa por '.
                'alicuota, asi que sin ella estos tests no miden nada.'
            );
        }

        $afip_information = AfipInformation::create([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva_condition->id,
            'razon_social'           => 'Comercio de test claves de alicuota',
            'cuit'                   => '20000000000',
            'punto_venta'            => 98,
            // En 0 a proposito: marca homologacion. Aca no se emite nada, pero un fixture de
            // testing no puede quedar marcado como produccion.
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $afip_information->id;

        return $afip_information;
    }

    /**
     * Una venta terminada con su factura ya autorizada por ARCA.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  float $total
     * @return array{sale: \App\Models\Sale, afip_ticket: \App\Models\AfipTicket}
     */
    protected function venta_facturada($afip_information, $total)
    {
        $venta = Sale::create([
            'user_id'    => $this->usuario_de_testing()->id,
            'moneda_id'  => 1,
            'total'      => $total,
            'terminada'  => 1,
            'created_at' => self::FECHA,
        ]);

        $this->sembrado['sales'][] = $venta->id;

        $afip_ticket = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $afip_information->id,
            'punto_venta'         => 98,
            'cbte_tipo'           => '1',
            'cbte_letra'          => 'A',
            'cbte_numero'         => (string) $venta->id,
            'resultado'           => 'A',
            'importe_total'       => $total,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '00000000000000',
            'afip_fecha_emision'  => '2014-03-10',
            'created_at'          => self::FECHA,
        ]);

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return ['sale' => $venta, 'afip_ticket' => $afip_ticket];
    }

    /**
     * El movimiento de cuenta corriente de la nota de credito, mas su comprobante autorizado.
     *
     * El comprobante nace con `sale_id` en NULL y `nota_credito_id` apuntando al movimiento: es
     * exactamente como lo crea `AfipNotaCreditoHelper::create_afip_ticket()`, y es lo que hace que
     * `AfipController::get_importes()` entre por la rama de nota de credito — la UNICA por la que
     * llegan las lineas de descripcion libre.
     *
     * @param  \App\Models\AfipInformation $afip_information
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\AfipTicket $afip_ticket_venta
     * @param  float $haber
     * @return array{nota_credito: \App\Models\CurrentAcount, afip_ticket: \App\Models\AfipTicket}
     */
    protected function nota_credito_facturada($afip_information, $venta, $afip_ticket_venta, $haber)
    {
        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test claves de alicuota',
            'description' => 'Devolucion de test',
            'haber'       => $haber,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
            'created_at'  => self::FECHA,
        ]);

        $this->sembrado['current_acounts'][] = $nota_credito->id;

        $afip_ticket = AfipTicket::create([
            'afip_information_id'  => $afip_information->id,
            'punto_venta'          => 98,
            'nota_credito_id'      => $nota_credito->id,
            'sale_nota_credito_id' => $venta->id,
            'sale_afip_ticket_id'  => $afip_ticket_venta->id,
            'cbte_tipo'            => '3',
            'cbte_letra'           => 'A',
            'cbte_numero'          => (string) ($venta->id + 1),
            'resultado'            => 'A',
            'importe_total'        => $haber,
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000001',
            'afip_fecha_emision'   => '2014-03-10',
            'created_at'           => self::FECHA,
        ]);

        $this->sembrado['afip_tickets'][] = $afip_ticket->id;

        return ['nota_credito' => $nota_credito, 'afip_ticket' => $afip_ticket];
    }

    /**
     * Cuelga un articulo del fixture (de una venta o de una nota de credito) con su alicuota
     * historica en el pivot.
     *
     * 🔴 La alicuota va como texto CRUDO ('10.5', no '10' ni '10.50'): asi la compara
     * `AfipItemCalculator::get_importe_iva()`, con `(string) $pivot->iva_percentage == (string) $iva`.
     * Escribir aca la clave interna '10' romperia el escenario sin que se note.
     *
     * @param  mixed $modelo Sale o CurrentAcount (los dos tienen `articles()`).
     * @param  string $nombre_articulo
     * @param  float $price Precio unitario CON IVA incluido, como en todo el sistema.
     * @param  float $amount
     * @param  string $iva_percentage
     * @return void
     */
    protected function agregar_articulo($modelo, $nombre_articulo, $price, $amount, $iva_percentage)
    {
        $articulo = $this->articulo($nombre_articulo);

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "'.$nombre_articulo.'" en el fixture de testing.');
        }

        if ((string) $articulo->iva->percentage !== (string) $iva_percentage) {
            $this->fail(
                'El articulo "'.$nombre_articulo.'" del fixture ya no esta al '.$iva_percentage.' % '.
                '(esta al "'.$articulo->iva->percentage.'"). Estos tests miden el bucket de 10,5 %, '.
                'asi que el escenario dejaria de medir lo que dice medir. Es un problema del fixture '.
                'para reportar, no algo para saltear.'
            );
        }

        $modelo->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'iva_percentage' => $iva_percentage,
        ]);
    }

    /**
     * Cuelga de la nota de credito una linea de descripcion libre con la alicuota pedida.
     *
     * @param  \App\Models\CurrentAcount $nota_credito
     * @param  string $notes
     * @param  float $price Precio CON IVA incluido.
     * @param  string $iva_percentage Valor exacto de `ivas.percentage`.
     * @return \App\Models\NotaCreditoDescription
     */
    protected function agregar_descripcion($nota_credito, $notes, $price, $iva_percentage)
    {
        $iva = Iva::where('percentage', $iva_percentage)->first();

        if (is_null($iva)) {
            $this->fail('No existe la alicuota "'.$iva_percentage.'" en la tabla ivas del fixture.');
        }

        $description = NotaCreditoDescription::create([
            'current_acount_id' => $nota_credito->id,
            'notes'             => $notes,
            'price'             => $price,
            'iva_id'            => $iva->id,
        ]);

        $this->sembrado['nota_credito_descriptions'][] = $description->id;

        return $description;
    }

    /**
     * ESCENARIO E1 — venta con un solo articulo al 10,5 %.
     *
     * Cuchilla, 1 unidad a 1105,00 (precio CON IVA):
     *   base = 1105 / 1,105 = 1000,00   IVA = 1000 * 0,105 = 105,00   total = 1105,00
     *
     * @return array{afip_information: \App\Models\AfipInformation, sale: \App\Models\Sale, afip_ticket: \App\Models\AfipTicket}
     */
    protected function escenario_e1()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 1105);

        $this->agregar_articulo($venta['sale'], 'Cuchilla', 1105, 1, '10.5');

        return [
            'afip_information' => $afip_information,
            'sale'             => Sale::find($venta['sale']->id),
            'afip_ticket'      => AfipTicket::find($venta['afip_ticket']->id),
        ];
    }

    /**
     * ESCENARIO E2 — nota de credito con un articulo al 10,5 % Y una descripcion libre al 10,5 %.
     *
     *   articulo Cuchilla, 1 unidad a 1105,00 -> base 1000,00 / IVA 105,00
     *   descripcion "Reintegro por flete", 552,50 al 10,5 % -> base 500,00 / IVA 52,50
     *
     *   Post-arreglo: UN solo renglon, clave '10', BaseImp 1500,00, Importe 157,50, Id 4.
     *   Pre-arreglo:  DOS renglones, '10' (Id 4, 105,00) y '10.5' (Id 0, 52,50).
     *
     * 🔴 Tiene que ser una nota de credito y no una venta: `AfipController::get_importes()` deja
     * `$descriptions = []` en la rama de la factura de venta, asi que las lineas de descripcion
     * libre solo entran por aca. O sea que este bug es, en la practica, un bug de notas de credito.
     *
     * @return array{afip_information: \App\Models\AfipInformation, sale: \App\Models\Sale, venta_afip_ticket: \App\Models\AfipTicket, nota_credito: \App\Models\CurrentAcount, afip_ticket: \App\Models\AfipTicket}
     */
    protected function escenario_e2()
    {
        $afip_information = $this->configuracion_fiscal_responsable_inscripto();
        $venta = $this->venta_facturada($afip_information, 5000);
        $nc = $this->nota_credito_facturada($afip_information, $venta['sale'], $venta['afip_ticket'], 1657.50);

        $this->agregar_articulo($nc['nota_credito'], 'Cuchilla', 1105, 1, '10.5');
        $this->agregar_descripcion($nc['nota_credito'], 'Reintegro por flete', 552.50, '10.5');

        return [
            'afip_information'  => $afip_information,
            'sale'              => $venta['sale'],
            'venta_afip_ticket' => AfipTicket::find($venta['afip_ticket']->id),
            'nota_credito'      => $nc['nota_credito'],
            'afip_ticket'       => AfipTicket::find($nc['afip_ticket']->id),
        ];
    }

    /**
     * Persiste el snapshot fiscal de autorizacion en el comprobante, que es lo que hace que
     * `AfipImportesResolver::resolve()` deje de recalcular y lea `iva_detalle_enviado_json`.
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @param  float $total
     * @param  float $neto
     * @param  float $iva
     * @param  array $detalle Renglones tal cual se le mandaron a ARCA: [['Id' => 4, ...]].
     * @return \App\Models\AfipTicket
     */
    protected function poner_snapshot($afip_ticket, $total, $neto, $iva, $detalle)
    {
        $afip_ticket->update([
            'imp_total_enviado'        => $total,
            'imp_tot_conc_enviado'     => 0,
            'imp_neto_enviado'         => $neto,
            'imp_op_ex_enviado'        => 0,
            'imp_iva_enviado'          => $iva,
            'iva_detalle_enviado_json' => $detalle,
        ]);

        return AfipTicket::find($afip_ticket->id);
    }

    // -----------------------------------------------------------------------------------------
    // Invocacion de los consumidores reales
    // -----------------------------------------------------------------------------------------

    /**
     * Filas del Libro IVA Ventas de marzo de 2014.
     *
     * Se llama `comprobantes_del_libro_iva_ventas()` y NO `iva_ventas_pdf()` porque
     * `LibroIvaVentaPdf::__construct()` termina en `$this->Output(); exit;` y mataria el proceso de
     * PHPUnit. Es exactamente la costura que la mision extrajo para poder medir esto.
     *
     * @return array
     */
    protected function filas_del_libro()
    {
        $afip_controller = new AfipController();

        return $afip_controller->comprobantes_del_libro_iva_ventas(
            Carbon::parse(self::PERIODO)->startOfMonth(),
            Carbon::parse(self::PERIODO)->endOfMonth()
        );
    }

    /**
     * Busca en el libro la fila de un comprobante puntual, por su numero impreso.
     *
     * @param  array $filas
     * @param  \App\Models\AfipTicket $afip_ticket
     * @return array
     */
    protected function fila_del_comprobante($filas, $afip_ticket)
    {
        $afip_controller = new AfipController();
        $num = $afip_controller->build_num_comprobante_afip($afip_ticket);

        foreach ($filas as $fila) {
            if ($fila['num_comprobante'] === $num) {
                return $fila;
            }
        }

        $this->fail(
            'El comprobante "'.$num.'" no aparece en el Libro IVA Ventas de '.self::PERIODO.'. '.
            'Sin la fila no hay nada que medir: revisar el fixture (cae, user_id de la venta, fecha).'
        );
    }

    /**
     * Corre `exportAlicuotasTxt()` de marzo de 2014 y devuelve sus lineas.
     *
     * Sin `Storage::fake()` a proposito: el metodo termina en
     * `response()->download(storage_path(...))`, que necesita el archivo real en disco.
     *
     * @return array<int, string>
     */
    protected function lineas_del_txt_de_alicuotas()
    {
        $afip_controller = new AfipController();
        $afip_controller->exportAlicuotasTxt(self::PERIODO, self::PERIODO);

        $archivo = 'Alicuotas_'.self::PERIODO.'_a_'.self::PERIODO.'.txt';
        $this->archivos_generados[] = $archivo;

        return $this->lineas_de($archivo);
    }

    /**
     * Corre `exportVentas()` de marzo de 2014 y devuelve sus lineas.
     *
     * Sin `Storage::fake()`, por lo mismo que `lineas_del_txt_de_alicuotas()`: el metodo termina
     * en `response()->download(storage_path(...))` y necesita el archivo real en disco.
     *
     * @return array<int, string>
     */
    protected function lineas_del_txt_de_comprobantes()
    {
        $afip_controller = new AfipController();
        $afip_controller->exportVentas(self::PERIODO, self::PERIODO);

        $archivo = 'Comprobantes_'.self::PERIODO.'_a_'.self::PERIODO.'.txt';
        $this->archivos_generados[] = $archivo;

        return $this->lineas_de($archivo);
    }

    /**
     * Offset donde arranca el campo "cantidad de alicuotas" dentro de una linea de `exportVentas()`.
     *
     * Sale de sumar los campos fijos que van antes, todos con `str_pad()` de ancho conocido:
     *   fecha 8 | tipo cbte 3 | punto vta 5 | nro cbte 20 | nro cbte hasta 20 | cod doc 2 |
     *   nro doc 20 | comprador 30 | imp total 15 | no gravados 15 | perc no categ 15 |
     *   exento 15 | perc nacional 15 | perc iibb 15 | perc municipal 15 | imp internos 15 |
     *   moneda 3 | tipo cambio 10   =   241
     */
    const OFFSET_CANTIDAD_IVA = 241;

    /**
     * Ancho de la cola que va DESPUES de la cantidad de alicuotas: codigo de operacion (1) +
     * otros tributos (15) + fecha de vencimiento (8).
     */
    const ANCHO_COLA_COMPROBANTE = 24;

    /**
     * Busca en el TXT de comprobantes la linea de un comprobante puntual.
     *
     * Hace falta porque `exportVentas()` no scopea por usuario y en marzo de 2014 este fixture deja
     * DOS comprobantes con CAE: la factura de la venta y la nota de credito. Se matchea por el
     * bloque tipo+punto de venta+numero, que arranca en el offset 8 y ocupa 28 caracteres.
     *
     * @param  array<int, string> $lineas
     * @param  \App\Models\AfipTicket $afip_ticket
     * @return string
     */
    protected function linea_del_txt_de_comprobantes($lineas, $afip_ticket)
    {
        /** @var string $clave Tipo (3) + punto de venta (5) + numero (20), como los arma exportVentas(). */
        $clave = str_pad($afip_ticket->cbte_tipo, 3, '0', STR_PAD_LEFT)
            .str_pad($afip_ticket->punto_venta, 5, '0', STR_PAD_LEFT)
            .str_pad($afip_ticket->cbte_numero, 20, '0', STR_PAD_LEFT);

        foreach ($lineas as $linea) {
            if (substr($linea, 8, 28) === $clave) {
                return $linea;
            }
        }

        $this->fail(
            'El comprobante "'.$clave.'" no aparece en el TXT de comprobantes de '.self::PERIODO.'. '.
            'Sin la linea no hay nada que medir: revisar el fixture (cae, fecha, cbte_numero). '.
            'Lineas encontradas: '.count($lineas).'.'
        );
    }

    /**
     * Lee el campo "cantidad de alicuotas" TAL COMO QUEDO ESCRITO en la linea del TXT.
     *
     * 🔴 Esto es lo que hace que el test valga, y no se puede reemplazar por `get_cantidad_iva()`:
     * ese campo se concatena CRUDO, sin `str_pad()`, asi que su ancho depende del valor. Un
     * contador que devuelve 2 no solo declara mal la cantidad: corre un caracter TODA la cola de
     * la linea (codigo de operacion, otros tributos y fecha de vencimiento). Medir el contador
     * aislado no diria nada sobre el archivo que el contador le sube a ARCA.
     *
     * Por eso el campo se recorta contra el final de la linea y no con un ancho fijo: es
     * exactamente la fragilidad que se esta midiendo.
     *
     * @param  string $linea
     * @return string Contenido crudo del campo, sin castear.
     */
    protected function cantidad_de_alicuotas_declarada($linea)
    {
        /** @var int $largo Lo que ocupa el campo: lo que sobra entre el offset fijo y la cola fija. */
        $largo = strlen($linea) - self::OFFSET_CANTIDAD_IVA - self::ANCHO_COLA_COMPROBANTE;

        if ($largo < 1) {
            $this->fail(
                'La linea del TXT de comprobantes es mas corta de lo que permite su propio layout '.
                '('.strlen($linea).' caracteres). Si cambio algun campo de exportVentas(), hay que '.
                'actualizar OFFSET_CANTIDAD_IVA y ANCHO_COLA_COMPROBANTE. Linea: "'.$linea.'"'
            );
        }

        return substr($linea, self::OFFSET_CANTIDAD_IVA, $largo);
    }

    /**
     * Lee un archivo del disco `local` y lo parte en lineas no vacias.
     *
     * @param  string $archivo
     * @return array<int, string>
     */
    protected function lineas_de($archivo)
    {
        if (!Storage::disk('local')->exists($archivo)) {
            $this->fail('El export no dejo el archivo "'.$archivo.'" en el disco local.');
        }

        /** @var string $contenido Contenido crudo del TXT, con separador CRLF. */
        $contenido = Storage::disk('local')->get($archivo);

        /** @var array<int, string> $lineas Lineas no vacias. */
        $lineas = [];

        foreach (explode("\r\n", $contenido) as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            $lineas[] = $linea;
        }

        return $lineas;
    }

    /**
     * Dibuja el bloque de importes del PDF fiscal sobre el espia y devuelve las celdas.
     *
     * `print_footer_importes_block()` es `protected static`, asi que se invoca por reflexion. NO se
     * llama `AfipPdfHelper::footer()`, que ademas dibuja el QR de ARCA (imagen y red).
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @param  \App\Models\Sale $sale
     * @return array<int, string>
     */
    protected function celdas_del_pdf_fiscal($afip_ticket, $sale)
    {
        $espia = new EspiaDePdf();
        $afip_helper = new AfipHelper($afip_ticket, null, null, $this->usuario_de_testing(), $sale);

        $metodo = new ReflectionMethod(AfipPdfHelper::class, 'print_footer_importes_block');
        $metodo->setAccessible(true);
        $metodo->invoke(null, $espia, $afip_ticket, $sale, $afip_helper);

        return $espia->renglones;
    }

    /**
     * Dibuja el cuadro de IVA del ticket de venta sobre el espia y devuelve las celdas.
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @param  \App\Models\Sale $sale
     * @return array<int, string>
     */
    protected function celdas_del_ticket($afip_ticket, $sale)
    {
        $espia = new EspiaDePdf();
        $ticket_info_helper = new TicketInfoHelper($afip_ticket, $sale, $this->usuario_de_testing());

        $ticket_info_helper->print_iva_and_totals($espia, $sale);

        return $espia->renglones;
    }

    /**
     * Devuelve el valor que se imprimio JUSTO DESPUES de una etiqueta dada.
     *
     * Los dos bloques dibujan cada renglon como dos celdas seguidas: la etiqueta y el importe. Este
     * helper es el que permite asertar que "IVA 10.5%:" sale con `$105` y no con `$0`, que es
     * exactamente la forma que tenia el defecto.
     *
     * @param  array<int, string> $celdas
     * @param  string $etiqueta
     * @return string|null Null si la etiqueta no se imprimio.
     */
    protected function valor_de_la_etiqueta($celdas, $etiqueta)
    {
        foreach ($celdas as $indice => $celda) {
            if ($celda === $etiqueta && isset($celdas[$indice + 1])) {
                return $celdas[$indice + 1];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------------------------
    // A1-A2 — Libro IVA Ventas
    // -----------------------------------------------------------------------------------------

    /**
     * A1 — Libro IVA Ventas por RECALCULO: la descripcion libre al 10,5 % cae en el mismo renglon
     * que el articulo al 10,5 %.
     *
     * Post-arreglo el renglon de 10,5 % de la nota de credito vale 157,50 (105,00 del articulo +
     * 52,50 de la descripcion). Pre-arreglo valia 105,00: la descripcion se iba a un bucket `'10.5'`
     * que el libro no lee nunca, y esos 52,50 desaparecian del reporte que va al contador.
     *
     * El signo negativo es de la nota de credito (`$sign = -1` en el libro), no del arreglo.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_libro_iva_ventas_por_recalculo_junta_la_descripcion_libre_en_el_renglon_de_10_5()
    {
        $e2 = $this->escenario_e2();

        $fila = $this->fila_del_comprobante($this->filas_del_libro(), $e2['afip_ticket']);

        $this->assertEqualsWithDelta(
            -157.50,
            (float) $fila['iva_10'],
            self::DELTA,
            'renglon de 10,5 % de la nota de credito en el Libro IVA Ventas (literal: 105,00 del '.
            'articulo + 52,50 de la descripcion libre, con el signo -1 de la NC). Si da -105,00, la '.
            'descripcion volvio a caer en el bucket "10.5" que este reporte no lee'
        );

        $this->assertEqualsWithDelta(
            -1500.00,
            (float) $fila['neto'],
            self::DELTA,
            'neto gravado de la nota de credito (literal: 1000,00 + 500,00)'
        );

        $this->assertEqualsWithDelta(
            -1657.50,
            (float) $fila['total'],
            self::DELTA,
            'total de la nota de credito (literal: 1500,00 + 157,50)'
        );

        /*
         * La otra mitad: el renglon de 10,5 % tiene que cerrar contra el IVA total del comprobante.
         * Pre-arreglo no cerraba —el libro mostraba 105,00 sobre un total que ya incluia los
         * 157,50—, y ese descuadre es el que no se veia desde ningun archivo.
         */
        $importes = (new AfipController())->get_importes(AfipTicket::find($e2['afip_ticket']->id));

        $this->assertEqualsWithDelta(
            (float) $importes['iva'],
            abs((float) $fila['iva_10']),
            self::DELTA,
            'ESCENARIO MAL ARMADO si esto falla: en E2 todo el IVA del comprobante es de 10,5 %, '.
            'asi que el renglon del libro tiene que ser IGUAL al IVA total. Si difieren, hay plata '.
            'de IVA que el libro no esta mostrando en ningun renglon'
        );
    }

    /**
     * A2 — Libro IVA Ventas por SNAPSHOT: da lo mismo que por recalculo, y un comprobante cuyo
     * snapshot no declara el 21 % NO revienta el libro entero.
     *
     * Las dos mitades son el mismo bug visto de dos lados:
     *
     *  1. El snapshot traduce el `Id` de ARCA a clave interna. Cuando emitia `'10.5'`, el libro
     *     —que lee `['ivas']['10']`— mostraba CERO para un comprobante autorizado con 157,50 de IVA
     *     al 10,5 %. El mismo comprobante daba distinto segun por donde entraba.
     *  2. El acceso directo `$importes['ivas']['21']['Importe']` sin `isset` no queda en un Notice
     *     que evalua a 0: Laravel 8 convierte cualquier error reportado en `ErrorException`
     *     (`HandleExceptions::handleError()`), asi que UN comprobante sin renglon al 21 % tiraba
     *     500 y se llevaba puesto el libro COMPLETO del periodo.
     *
     * 🔴 La segunda mitad es la que el plan pedia verificar en vez de darla por cierta: se comprueba
     * que el libro se arma entero, y que la fila de la factura de venta —que no tiene nada al 21 %—
     * sigue apareciendo.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_libro_iva_ventas_por_snapshot_da_lo_mismo_que_por_recalculo_y_no_revienta_sin_el_21()
    {
        $e2 = $this->escenario_e2();

        $fila_por_recalculo = $this->fila_del_comprobante($this->filas_del_libro(), $e2['afip_ticket']);

        // El snapshot declara EXACTAMENTE lo mismo que el recalculo, con el Id de ARCA de 10,5 % (4).
        $this->poner_snapshot($e2['afip_ticket'], 1657.50, 1500.00, 157.50, [
            ['Id' => 4, 'BaseImp' => 1500.00, 'Importe' => 157.50],
        ]);

        $filas = $this->filas_del_libro();
        $fila_por_snapshot = $this->fila_del_comprobante($filas, $e2['afip_ticket']);

        $this->assertEqualsWithDelta(
            -157.50,
            (float) $fila_por_snapshot['iva_10'],
            self::DELTA,
            'renglon de 10,5 % leido del snapshot de autorizacion (Id 4). Si da 0, el snapshot volvio '.
            'a emitir la clave "10.5" y el libro, que lee "10", no la encuentra'
        );

        $this->assertEqualsWithDelta(
            (float) $fila_por_recalculo['iva_10'],
            (float) $fila_por_snapshot['iva_10'],
            self::DELTA,
            'LA UNIFICACION: el mismo comprobante tiene que dar el MISMO renglon de 10,5 % por '.
            'recalculo y por snapshot. Que difirieran era el bug entero'
        );

        /*
         * Segunda mitad: el comprobante con snapshot NO declara alicuota al 21 %, asi que
         * `$importes['ivas']['21']` no existe. Llegar hasta aca ya prueba que no se tira
         * ErrorException; ademas se comprueba que la columna sale vacia y no inventa un numero.
         */
        $this->assertSame(
            '',
            $fila_por_snapshot['iva_21'],
            'un comprobante cuyo snapshot no trae renglon al 21 % tiene que salir con la columna '.
            'VACIA, no con un importe inventado'
        );

        $this->assertSame(
            '',
            $fila_por_snapshot['iva_27'],
            'idem para el 27 %, que tampoco esta en el snapshot'
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($filas),
            'ESCENARIO MAL ARMADO si esto falla: el libro tiene que traer la factura de venta Y la '.
            'nota de credito. Con el acceso directo a ["ivas"]["21"] el metodo entero tiraba '.
            'ErrorException y no devolvia ninguna fila'
        );
    }

    // -----------------------------------------------------------------------------------------
    // A3-A4 — PDF del comprobante
    // -----------------------------------------------------------------------------------------

    /**
     * A3 — PDF fiscal por RECALCULO: el renglon de 10,5 % se imprime CON la plata adentro.
     *
     * El bloque imprime siempre las seis alicuotas (el alto es fijo). Antes recorria una lista de
     * etiquetas —`['27','21','10.5','5','2.5','0']`— y usaba esa etiqueta TAMBIEN como clave de
     * lectura del bucket: como el bucket se llama `'10'`, la busqueda de `'10.5'` no encontraba
     * nada y el papel salia con "IVA 10.5%: $0" teniendo 105 pesos de IVA en el comprobante.
     *
     * 🔴 Por eso no alcanza con asertar que la etiqueta existe: se aserta el VALOR de al lado.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_pdf_fiscal_por_recalculo_imprime_el_renglon_de_10_5_con_su_importe()
    {
        $e1 = $this->escenario_e1();

        $celdas = $this->celdas_del_pdf_fiscal($e1['afip_ticket'], $e1['sale']);

        $this->assertContains(
            'IVA 10.5%:',
            $celdas,
            'el PDF fiscal tiene que imprimir la ETIQUETA "10.5", que es el porcentaje real, y no la '.
            'clave interna del bucket ("10")'
        );

        $this->assertNotContains(
            'IVA 10%:',
            $celdas,
            'si aparece "IVA 10%:" es que se esta imprimiendo la CLAVE del bucket en vez de la '.
            'etiqueta: el papel diria 10 % donde va 10,5 %'
        );

        $this->assertSame(
            '$105',
            $this->valor_de_la_etiqueta($celdas, 'IVA 10.5%:'),
            'EL DEFECTO EN UNA LINEA: el renglon de 10,5 % tiene que traer los 105,00 de IVA del '.
            'comprobante. Un "$0" ahi significa que la etiqueta se esta usando tambien como clave de '.
            'lectura del bucket y no lo encuentra'
        );

        $this->assertSame(
            '$0',
            $this->valor_de_la_etiqueta($celdas, 'IVA 21%:'),
            'ESCENARIO MAL ARMADO si esto falla: la venta de E1 no tiene nada al 21 %, asi que ese '.
            'renglon tiene que salir en cero. Si trae plata, el escenario no es el que dice ser'
        );
    }

    /**
     * A4 — PDF fiscal por SNAPSHOT: el bloque impreso es CARACTER POR CARACTER el mismo que por
     * recalculo.
     *
     * Es la asercion que ninguno de los dos caminos podia hacer por separado. Antes, el mismo
     * comprobante se imprimia distinto segun la fuente: por recalculo el bucket se llamaba `'10'` y
     * por snapshot `'10.5'`, y el que quedaba sin encontrar imprimia `$0`.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_pdf_fiscal_imprime_lo_mismo_por_snapshot_que_por_recalculo()
    {
        $e1 = $this->escenario_e1();

        $celdas_por_recalculo = $this->celdas_del_pdf_fiscal($e1['afip_ticket'], $e1['sale']);

        // El snapshot declara lo mismo que el recalculo de E1, con el Id de ARCA de 10,5 % (4).
        $afip_ticket = $this->poner_snapshot($e1['afip_ticket'], 1105.00, 1000.00, 105.00, [
            ['Id' => 4, 'BaseImp' => 1000.00, 'Importe' => 105.00],
        ]);

        $celdas_por_snapshot = $this->celdas_del_pdf_fiscal($afip_ticket, $e1['sale']);

        $this->assertSame(
            '$105',
            $this->valor_de_la_etiqueta($celdas_por_snapshot, 'IVA 10.5%:'),
            'leyendo el snapshot de autorizacion (Id 4), el renglon de 10,5 % tiene que traer los '.
            '105,00. Un "$0" significa que el snapshot volvio a emitir la clave "10.5"'
        );

        $this->assertSame(
            $celdas_por_recalculo,
            $celdas_por_snapshot,
            'LA UNIFICACION: el mismo comprobante tiene que imprimirse IGUAL venga el desglose del '.
            'recalculo o del snapshot. Cualquier diferencia aca es una convencion de clave que se '.
            'volvio a partir en dos'
        );
    }

    // -----------------------------------------------------------------------------------------
    // A5-A8 — los dos TXT que el contador le sube a ARCA
    // -----------------------------------------------------------------------------------------

    /**
     * A5 — TXT de alicuotas por RECALCULO: un solo renglon, con codigo de alicuota 0004 y ninguno
     * con codigo 0000.
     *
     * Formato de la linea (`exportAlicuotasTxt`), posiciones fijas:
     *   tipo comprobante (3) + punto de venta (5) + numero (20) + neto (15) + codigo (4) + IVA (15)
     *
     * Pre-arreglo salian DOS renglones para el mismo comprobante: `0004` con 1000,00/105,00 y otro
     * con codigo `0000` —el `Id => 0` del bucket inventado— con 500,00/52,50. Un `0000` es una
     * alicuota que ARCA no tiene: el archivo lo rechaza o lo toma mal, y en los dos casos el
     * desglose fiscal del comercio queda mal declarado.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_txt_de_alicuotas_por_recalculo_saca_un_solo_renglon_con_el_codigo_de_arca()
    {
        $this->escenario_e2();

        $lineas = $this->lineas_del_txt_de_alicuotas();

        $this->assertCount(
            1,
            $lineas,
            'E2 tiene TODA su plata al 10,5 %, asi que el archivo de alicuotas tiene que traer un '.
            'unico renglon. Dos renglones significa que la descripcion libre se fue a un bucket '.
            'propio: '.implode(' | ', $lineas)
        );

        /** @var string $linea Unico renglon del archivo. */
        $linea = $lineas[0];

        $this->assertSame(
            '0004',
            substr($linea, 43, 4),
            'codigo de alicuota de ARCA para 10,5 %. Un "0000" es el Id inventado del bucket '.
            'desconocido, que es justamente lo que esta mision hace imposible'
        );

        $this->assertSame(
            '000000000150000',
            substr($linea, 28, 15),
            'neto del renglon (1500,00 = 1000,00 del articulo + 500,00 de la descripcion libre)'
        );

        $this->assertSame(
            '000000000015750',
            substr($linea, 47, 15),
            'IVA del renglon (157,50 = 105,00 del articulo + 52,50 de la descripcion libre)'
        );

        foreach ($lineas as $indice => $cada_linea) {
            $this->assertNotSame(
                '0000',
                substr($cada_linea, 43, 4),
                'el renglon '.$indice.' del archivo de alicuotas salio con codigo 0000. Ese codigo '.
                'no existe en ARCA: significa que se armo un bucket de IVA sin saber que alicuota es'
            );
        }
    }

    /**
     * A6 — TXT de alicuotas por SNAPSHOT: contenido IDENTICO al del recalculo.
     *
     * 🔴 Esta es la que demuestra la inversion, y por eso las dos corridas van en el mismo test.
     * Pre-arreglo la igualdad era FALSA: por recalculo salian dos lineas (una con codigo `0000`) y
     * por snapshot una sola con `0004`. El mismo comprobante, el mismo mes, dos archivos distintos
     * para el contador segun si el ticket tenia snapshot o no.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_txt_de_alicuotas_sale_identico_por_snapshot_que_por_recalculo()
    {
        $e2 = $this->escenario_e2();

        $lineas_por_recalculo = $this->lineas_del_txt_de_alicuotas();

        $this->poner_snapshot($e2['afip_ticket'], 1657.50, 1500.00, 157.50, [
            ['Id' => 4, 'BaseImp' => 1500.00, 'Importe' => 157.50],
        ]);

        $lineas_por_snapshot = $this->lineas_del_txt_de_alicuotas();

        $this->assertCount(
            1,
            $lineas_por_snapshot,
            'con snapshot tambien tiene que salir un unico renglon: '.implode(' | ', $lineas_por_snapshot)
        );

        $this->assertSame(
            '0004',
            substr($lineas_por_snapshot[0], 43, 4),
            'codigo de alicuota de ARCA leido del snapshot de autorizacion (Id 4)'
        );

        $this->assertSame(
            $lineas_por_recalculo,
            $lineas_por_snapshot,
            'LA INVERSION: el archivo que el contador le sube a ARCA tiene que salir IGUAL venga el '.
            'desglose del recalculo o del snapshot. Pre-arreglo esta igualdad era falsa (2 lineas '.
            'contra 1) y nadie podia verlo desde un solo archivo del sistema'
        );
    }

    /**
     * A7 — TXT de comprobantes por RECALCULO: la cantidad de alicuotas declaradas es 1.
     *
     * `exportVentas()` escribe en cada linea cuantos renglones de alicuota tiene el comprobante, y
     * ese numero TIENE que coincidir con la cantidad de lineas que el archivo de alicuotas trae para
     * el mismo comprobante: es la validacion cruzada que hace ARCA al importar los dos archivos.
     * Pre-arreglo daba 2 —los dos buckets con plata—, contra un archivo de alicuotas que declaraba
     * un renglon `0000` que ARCA no reconoce.
     *
     * 🔴 Se corre `exportVentas()` DE VERDAD y se mide el contenido del archivo, no
     * `get_cantidad_iva()` a solas. El campo se concatena crudo, sin `str_pad()`: un 2 en vez de un
     * 1 no solo declara mal, corre un caracter toda la cola de la linea. Ver
     * `cantidad_de_alicuotas_declarada()`.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_txt_de_comprobantes_por_recalculo_declara_una_sola_alicuota()
    {
        $e2 = $this->escenario_e2();

        $linea = $this->linea_del_txt_de_comprobantes(
            $this->lineas_del_txt_de_comprobantes(),
            $e2['afip_ticket']
        );

        $this->assertSame(
            '1',
            $this->cantidad_de_alicuotas_declarada($linea),
            'E2 tiene toda su plata al 10,5 %: el archivo que el contador le sube a ARCA tiene que '.
            'declarar UNA sola alicuota. Un 2 significa que la descripcion libre armo un bucket '.
            'propio, y entonces el comprobante declara mas renglones de los que ARCA puede '.
            'reconocer en el archivo de alicuotas. Linea: "'.$linea.'"'
        );

        /*
         * La otra mitad: lo que se declara tiene que coincidir con lo que realmente sale en el
         * archivo de alicuotas. Los dos archivos se importan juntos y ARCA los cruza.
         */
        $this->assertCount(
            (int) $this->cantidad_de_alicuotas_declarada($linea),
            $this->lineas_del_txt_de_alicuotas(),
            'la cantidad de alicuotas declarada en el archivo de comprobantes tiene que ser EXACTAMENTE '.
            'la cantidad de renglones del archivo de alicuotas para ese comprobante'
        );

        /*
         * Y el contador aislado, que es lo que arma el campo. Se mantiene la asercion porque deja
         * el diagnostico mas cerca cuando esto se rompe, pero la celda de la matriz la cubre el
         * archivo real de arriba.
         */
        $afip_controller = new AfipController();

        $this->assertSame(
            1,
            $afip_controller->get_cantidad_iva(AfipTicket::find($e2['afip_ticket']->id)),
            'get_cantidad_iva() es el que arma el campo: si el archivo esta mal, empezar por aca'
        );
    }

    /**
     * A8 — TXT de comprobantes por SNAPSHOT: la linea del archivo es la misma que por recalculo.
     *
     * 🔴 La asercion fuerte es la ultima: la linea ENTERA tiene que salir identica venga el
     * desglose del recalculo o del snapshot de autorizacion. Pre-arreglo eso era falso, y el
     * contador aislado no alcanzaba para verlo porque el campo se concatena sin padding.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_txt_de_comprobantes_declara_la_misma_cantidad_de_alicuotas_por_snapshot()
    {
        $e2 = $this->escenario_e2();

        $linea_por_recalculo = $this->linea_del_txt_de_comprobantes(
            $this->lineas_del_txt_de_comprobantes(),
            $e2['afip_ticket']
        );

        $this->poner_snapshot($e2['afip_ticket'], 1657.50, 1500.00, 157.50, [
            ['Id' => 4, 'BaseImp' => 1500.00, 'Importe' => 157.50],
        ]);

        $linea_por_snapshot = $this->linea_del_txt_de_comprobantes(
            $this->lineas_del_txt_de_comprobantes(),
            AfipTicket::find($e2['afip_ticket']->id)
        );

        $this->assertSame(
            '1',
            $this->cantidad_de_alicuotas_declarada($linea_por_snapshot),
            'con snapshot el archivo tambien tiene que declarar una sola alicuota. Linea: '.
            '"'.$linea_por_snapshot.'"'
        );

        $this->assertSame(
            $linea_por_recalculo,
            $linea_por_snapshot,
            'LA UNIFICACION: la linea del archivo de comprobantes no puede depender de si el ticket '.
            'tiene snapshot o no. Es el mismo comprobante y es el mismo archivo para el contador'
        );

        /*
         * El contador aislado, por lo mismo que en A7: acorta el diagnostico, no reemplaza al
         * archivo.
         */
        $afip_controller = new AfipController();

        $this->assertSame(
            1,
            $afip_controller->get_cantidad_iva(AfipTicket::find($e2['afip_ticket']->id)),
            'get_cantidad_iva() es el que arma el campo: si el archivo esta mal, empezar por aca'
        );
    }

    /**
     * A11 — una alicuota que ARCA no reconoce NO revienta el Libro IVA Ventas del mes entero.
     *
     * 🔴 Este test cubre el CABLEADO, que es lo que ningun test del calculador puede ver:
     * `AfipImportesResolver::resolve()` —el funnel de TODOS los caminos de lectura— tiene que
     * llamar a `getImportes(true)`. Si lo llamara sin el parametro, UNA linea vieja de UN
     * comprobante ya autorizado tiraria `InvalidArgumentException`, y como ni
     * `comprobantes_del_libro_iva_ventas()` ni `exportVentas()` ni `exportAlicuotasTxt()` tienen
     * `try/catch` en ninguna parte, el reporte del MES COMPLETO se cae con 500.
     *
     * Que ese caso exista no es hipotetico: la tabla `ivas` trae `'50'` desde el seeder, y la
     * importacion de articulos por Excel (`ProcessRow.php:3530`, `LocalImportHelper.php:459`) le
     * mete texto crudo sin validar.
     *
     * Lo que se acepta a proposito: la linea al 50 % suma a `neto` pero no genera renglon de
     * alicuota. Es lo mismo que ya pasa con un ARTICULO a una alicuota desconocida.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function una_alicuota_desconocida_no_revienta_el_libro_iva_ventas_del_mes()
    {
        $e2 = $this->escenario_e2();

        // La linea envenenada: 1500,00 al 50 %, una alicuota que existe en la tabla `ivas` del
        // sistema pero que ARCA no reconoce.
        $this->agregar_descripcion($e2['nota_credito'], 'Servicio raro al 50', 1500, '50');

        /** @var array $filas El libro completo del periodo. Llegar hasta aca ya es la mitad del test. */
        $filas = $this->filas_del_libro();

        $this->assertGreaterThanOrEqual(
            2,
            count($filas),
            'EL DEFECTO EN UNA LINEA: si esto tira o devuelve menos filas, `resolve()` dejo de pedir '.
            'el modo tolerante y una sola linea vieja se lleva puesto el Libro IVA Ventas ENTERO'
        );

        $fila = $this->fila_del_comprobante($filas, $e2['afip_ticket']);

        $this->assertEqualsWithDelta(
            -157.50,
            (float) $fila['iva_10'],
            self::DELTA,
            'el renglon de 10,5 % del resto del comprobante tiene que salir igual: la linea que no '.
            'se pudo clasificar no puede arrastrar a las que si'
        );

        /*
         * Y los dos TXT, que son los otros dos consumidores sin try/catch. Se los corre enteros:
         * lo que se esta midiendo es que no exploten.
         */
        $lineas_de_alicuotas = $this->lineas_del_txt_de_alicuotas();

        $this->assertCount(
            1,
            $lineas_de_alicuotas,
            'la linea al 50 % no genera renglon de alicuota (no se le puede inventar un Id), asi que '.
            'el archivo sigue trayendo el unico renglon de 10,5 %: '.implode(' | ', $lineas_de_alicuotas)
        );

        $this->assertSame(
            '1',
            $this->cantidad_de_alicuotas_declarada(
                $this->linea_del_txt_de_comprobantes(
                    $this->lineas_del_txt_de_comprobantes(),
                    $e2['afip_ticket']
                )
            ),
            'y el archivo de comprobantes tiene que declarar esa misma cantidad: los dos se importan '.
            'juntos y ARCA los cruza'
        );
    }

    // -----------------------------------------------------------------------------------------
    // A9-A10 — ticket de venta (TicketInfoHelper)
    // -----------------------------------------------------------------------------------------

    /**
     * A9 — Ticket por RECALCULO: imprime "IVA 10.5%", no "IVA 10%".
     *
     * `TicketInfoHelper` recorria el arreglo de buckets e imprimia la CLAVE (`'10'`) como si fuera
     * el porcentaje. El cliente se llevaba un comprobante que decia 10 % donde va 10,5 %.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_ticket_por_recalculo_imprime_el_porcentaje_real_y_no_la_clave_del_bucket()
    {
        $e1 = $this->escenario_e1();

        $celdas = $this->celdas_del_ticket($e1['afip_ticket'], $e1['sale']);

        $this->assertContains(
            'IVA 10.5%:',
            $celdas,
            'el ticket tiene que decir 10.5 %, que es el porcentaje real de la alicuota'
        );

        $this->assertNotContains(
            'IVA 10%:',
            $celdas,
            'EL DEFECTO EN UNA LINEA: "IVA 10%:" es la CLAVE INTERNA del bucket impresa como si fuera '.
            'el porcentaje. Son 10,5 puntos, no 10'
        );

        $this->assertSame(
            '$105',
            $this->valor_de_la_etiqueta($celdas, 'IVA 10.5%:'),
            'el renglon tiene que traer los 105,00 de IVA del comprobante'
        );

        /*
         * El ticket, a diferencia del PDF fiscal, imprime SOLO los renglones con plata. Que sea uno
         * solo confirma que no quedo ningun bucket paralelo con la misma alicuota.
         */
        $renglones_de_iva = 0;

        foreach ($celdas as $celda) {
            if (strpos($celda, 'IVA ') === 0) {
                $renglones_de_iva++;
            }
        }

        $this->assertSame(
            1,
            $renglones_de_iva,
            'E1 tiene un solo articulo, a una sola alicuota: el ticket tiene que imprimir un solo '.
            'renglon de IVA'
        );
    }

    /**
     * A10 — Ticket por SNAPSHOT: el renglon es identico al del recalculo.
     *
     * Pre-arreglo el mismo comprobante se imprimia distinto segun la fuente: por recalculo decia
     * "IVA 10%" (la clave) y por snapshot "IVA 10.5%" (la otra clave). Las dos mal, y encima
     * distintas entre si.
     *
     * @group iva-claves-alicuota
     * @test
     */
    public function el_ticket_imprime_lo_mismo_por_snapshot_que_por_recalculo()
    {
        $e1 = $this->escenario_e1();

        $celdas_por_recalculo = $this->celdas_del_ticket($e1['afip_ticket'], $e1['sale']);

        $afip_ticket = $this->poner_snapshot($e1['afip_ticket'], 1105.00, 1000.00, 105.00, [
            ['Id' => 4, 'BaseImp' => 1000.00, 'Importe' => 105.00],
        ]);

        $celdas_por_snapshot = $this->celdas_del_ticket($afip_ticket, $e1['sale']);

        $this->assertSame(
            '$105',
            $this->valor_de_la_etiqueta($celdas_por_snapshot, 'IVA 10.5%:'),
            'leyendo el snapshot (Id 4), el renglon de 10,5 % tiene que traer los 105,00'
        );

        $this->assertSame(
            $celdas_por_recalculo,
            $celdas_por_snapshot,
            'LA UNIFICACION: el ticket del mismo comprobante tiene que salir IGUAL venga el desglose '.
            'del recalculo o del snapshot'
        );
    }
}
