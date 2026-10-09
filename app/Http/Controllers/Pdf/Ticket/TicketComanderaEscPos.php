<?php

namespace App\Http\Controllers\Pdf\Ticket;

use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfLayout\CamposDeTicketPdf;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\sale\SaleTicketRasterHelper;
use App\Http\Controllers\Pdf\Afip\TicketInfoHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;

/**
 * El ticket de comandera de una venta armado con un DISEÑO (`pdf_column_profiles.page_layout` de un
 * perfil de ticket), en bytes ESC/POS para la impresora y en renglones de texto para la vista
 * previa, las dos cosas del MISMO armado (misión diseno-ticket-comandera, 9/10/2026, §4 del plan;
 * decisión D3: lo arma la API, no el navegador).
 *
 * POR QUÉ ACÁ Y NO EN EL NAVEGADOR. Los valores de cada campo salen de las mismas fuentes que el PDF
 * con cajas (CamposDeVentaPdf vía CamposDeTicketPdf, TotalesDeVentaPdf, PdfColumnService) y el logo
 * del mismo raster que el Ticket 2.0 (SaleTicketRasterHelper): "todas las propiedades de la venta
 * del A4" salen con los mismos números y formatos. El SPA recibe los bytes en base64 y los manda por
 * QZ o por el agente igual que hoy.
 *
 * 🔴 LAS REGLAS DE ABAJO LAS REPLICA EL SPA EN LA VISTA PREVIA DEL DISEÑADOR: si alguna cambia acá,
 * cambia allá (plan §4).
 *
 * - N = caracteres por renglón = floor(ancho_mm × 48 / 80).
 * - Empieza con `ESC t 2` (tabla CP850) y el texto va en CP850 de verdad (D11: las tildes salen
 *   bien). No manda `ESC @` (el de siempre tampoco). Termina con el corte de siempre:
 *   "\n\n\n\n" + `GS V 0` + "\n".
 * - CAJAS en la grilla de 12: una caja de c columnas mide floor(c × N / 12) caracteres; en una fila,
 *   cada caja menos la última deja uno de separación (contenido = ancho − 1). Las cajas de una fila
 *   salen lado a lado, renglón a renglón (la más corta se completa con espacios). Una caja sin
 *   ningún campo con datos no ocupa renglones (sus columnas quedan en blanco); una fila sin nada,
 *   tampoco.
 * - CAMPO: "Rótulo: valor" (rótulo del catálogo si la etiqueta es null, ninguno si es ''), partido
 *   por palabras al ancho de la caja (una palabra más larga, cortada). Una lista, un renglón por
 *   elemento (el rótulo en el primero). Alineación con espacios dentro de la caja.
 * - ESTILOS: negrita `ESC E 1/0`; tamaño < 12 normal (`GS ! 0x00`), 12..17 alto doble
 *   (`GS ! 0x01`), >= 18 grande (`GS ! 0x11`, cada carácter cuenta dos columnas). Cursiva: no hay en
 *   una térmica, se ignora. Un campo grande que no entra ni con una letra en su caja sale normal.
 * - TÍTULO de caja: en negrita, primero. ESTILO de caja `borde` o `gris`: una línea de guiones del
 *   ancho de la caja abajo; `ninguno`: nada.
 * - LOGO (`negocio_logo`): el raster de siempre, centrado a todo el ancho; si su caja comparte fila,
 *   el logo sale antes de los renglones de la fila. Sin logo, nada.
 * - TABLA, siempre entre la zona de arriba y el pie: las columnas visibles del perfil en su orden;
 *   cada una medias = max(1, round(ancho_mm_columna × 24 / ancho_mm)) y caracteres =
 *   floor(medias × N / 24); lo que sobra de N va a la columna con salto de línea (si no hay, a la más
 *   ancha). Uno de separación entre columnas (sale del ancho de cada una menos la última).
 *   Encabezado con los rótulos en negrita y una línea de guiones; un renglón por ítem; los valores
 *   numéricos a la derecha; con salto de línea el texto se parte, si no se corta. Línea de guiones al
 *   final. DIFERENCIA con el plan, a propósito: un valor NUMÉRICO que no entra en su columna no se
 *   corta (cortarlo cambia el número: "12" en una columna de 1 sería "1"), sigue en el renglón de
 *   abajo.
 * - FIJOS de ARCA (D8): BloquesFiscalesDeTicket, siempre a lo ancho. QR con el `GS ( k` del Ticket
 *   2.0 de siempre (modelo 2, punto 5, corrección 0x30).
 * - El texto (`lineas()`) es el mismo armado sin los comandos: "[LOGO]" donde va el logo y "[QR]"
 *   donde va el QR. Una letra grande se escribe una vez (en el papel ocupa dos columnas).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class TicketComanderaEscPos
{
    /** `ESC t 2`: tabla de caracteres CP850. */
    const INICIO = "\x1B\x74\x02";

    /** `ESC E 1` / `ESC E 0`: negrita. */
    const NEGRITA_SI = "\x1B\x45\x01";
    const NEGRITA_NO = "\x1B\x45\x00";

    /** `GS ! n`: tamaño de letra. */
    const TAMANO_NORMAL = "\x1D\x21\x00";
    const TAMANO_ALTO = "\x1D\x21\x01";
    const TAMANO_GRANDE = "\x1D\x21\x11";

    /** El corte de papel del Ticket 2.0 de siempre (cortar_papel()). */
    const CORTE = "\n\n\n\n\x1D\x56\x00\n";

    /** Marcas de la vista en texto. */
    const MARCA_LOGO = '[LOGO]';
    const MARCA_QR = '[QR]';

    /** Ancho de rollo cuando el perfil no dice ninguno (el de siempre del Ticket 2.0). */
    const ANCHO_POR_DEFECTO_MM = 80;

    /** @var \App\Models\Sale */
    protected $sale;

    /** @var PdfColumnProfile */
    protected $perfil;

    /** @var \App\Models\User|null Dueño de la venta. */
    protected $user;

    /** @var int Ancho del rollo en mm. */
    protected $ancho_mm;

    /** @var int Caracteres por renglón (N). */
    protected $caracteres;

    /** @var \App\Models\AfipTicket|null La factura que se imprime (solo con un perfil de factura). */
    protected $afip_ticket;

    /** @var bool Perfil de factura CON la factura resuelta: lleva los fijos de ARCA. */
    protected $es_fiscal;

    /** @var CamposDeTicketPdf */
    protected $fuente;

    /** @var array Diseño normalizado, con los fijos asegurados. */
    protected $diseno;

    /** @var array<string, array> Catálogo de ticket por key. */
    protected $catalogo;

    /** @var \App\Http\Controllers\Helpers\AfipHelper|null */
    protected $afip_helper;

    /** @var array<string, mixed> Valores ya pedidos a la fuente. */
    protected $valores;

    /** @var array<int, array>|null Las piezas, armadas una sola vez. */
    protected $piezas;

    /**
     * Prepara el ticket sin armar nada todavía.
     *
     * @param \App\Models\Sale            $sale
     * @param PdfColumnProfile            $perfil      un perfil de ticket de comandera.
     * @param \App\Models\AfipTicket|null $afip_ticket la factura con CAE que se imprime, o null.
     * @param array|null                  $diseno      el diseño a usar; null = el del perfil (el
     *                                                 endpoint pasa el DERIVADO para la vista previa
     *                                                 de un perfil sin diseño).
     * @param int|null                    $ancho_mm    el ancho del rollo; null = el de su tipo de hoja.
     */
    public function __construct($sale, PdfColumnProfile $perfil, $afip_ticket = null, $diseno = null, $ancho_mm = null)
    {
        $this->sale = $sale;
        $this->perfil = $perfil;
        $this->user = User::find($sale->user_id);
        $this->ancho_mm = $this->resolver_ancho_mm($perfil, $ancho_mm);
        $this->caracteres = CatalogoDeCamposPdf::caracteres_por_renglon($this->ancho_mm);
        $this->valores = [];
        $this->piezas = null;

        /** Un perfil de factura sin una factura con CAE sale como remito, sin los fijos (como el PDF). */
        $this->afip_ticket = (bool) $perfil->is_afip_ticket ? $afip_ticket : null;
        $this->es_fiscal = ! is_null($this->afip_ticket);

        $this->afip_helper = null;
        if ($this->es_fiscal) {
            $this->afip_helper = (new TicketInfoHelper($this->afip_ticket, $sale, $this->user))->afip_helper();
        }

        /**
         * El diseño pasa otra vez por normalizar() y asegurar_fijos() con el fiscal REAL (defensivo,
         * como SaleLayoutPdf): un perfil puede cambiar de "Es factura de ARCA" desde el formulario.
         */
        $normalizado = DisenoDePaginaPdf::normalizar(is_null($diseno) ? $perfil->page_layout : $diseno);
        $this->diseno = DisenoDePaginaPdf::asegurar_fijos(
            is_null($normalizado) ? DisenoDePaginaPdf::vacio() : $normalizado,
            $this->es_fiscal,
            true
        );

        $this->fuente = new CamposDeTicketPdf(
            $sale,
            $this->user,
            (bool) $perfil->use_current_date,
            $perfil->discount_display_mode === 'simple' ? 'simple' : 'descriptivo',
            $this->es_fiscal,
            $this->afip_ticket
        );

        $this->catalogo = [];
        foreach (CatalogoDeCamposPdf::campos('sale', true) as $campo) {
            $this->catalogo[$campo['key']] = $campo;
        }
    }

    /**
     * Ancho del rollo en mm.
     *
     * @return int
     */
    public function ancho_mm()
    {
        return $this->ancho_mm;
    }

    /**
     * Caracteres por renglón (N).
     *
     * @return int
     */
    public function caracteres_por_renglon()
    {
        return $this->caracteres;
    }

    /**
     * ¿Salió como factura (con los fijos de ARCA)?
     *
     * @return bool
     */
    public function es_fiscal()
    {
        return $this->es_fiscal;
    }

    /**
     * Los bytes ESC/POS listos para mandar a la impresora (por QZ o por el agente).
     *
     * @return string
     */
    public function bytes()
    {
        $salida = self::INICIO;

        /** Estado de la impresora: se manda un comando solo cuando cambia. */
        $negrita = false;
        $tamano = TextoDeTicket::NORMAL;

        foreach ($this->piezas() as $pieza) {
            if ($pieza['tipo'] === 'logo') {
                $salida .= $pieza['bytes'];
                continue;
            }

            if ($pieza['tipo'] === 'qr') {
                $salida .= self::bytes_del_qr($pieza['link']);
                continue;
            }

            foreach ($pieza['trozos'] as $trozo) {
                if (! is_null($trozo['negrita']) && (bool) $trozo['negrita'] !== $negrita) {
                    $negrita = (bool) $trozo['negrita'];
                    $salida .= $negrita ? self::NEGRITA_SI : self::NEGRITA_NO;
                }

                if ($trozo['tamano'] !== $tamano) {
                    $tamano = $trozo['tamano'];
                    $salida .= self::comando_de_tamano($tamano);
                }

                $salida .= TextoDeTicket::a_cp850($trozo['texto']);
            }

            $salida .= "\n";
        }

        /** La impresora queda como la encontró el Ticket 2.0 de siempre: sin negrita y en normal. */
        if ($negrita) {
            $salida .= self::NEGRITA_NO;
        }
        if ($tamano !== TextoDeTicket::NORMAL) {
            $salida .= self::TAMANO_NORMAL;
        }

        return $salida.self::CORTE;
    }

    /**
     * El ticket en texto plano (UTF-8), un renglón por elemento, sin comandos: "[LOGO]" y "[QR]"
     * donde van el logo y el QR.
     *
     * @return array<int, string>
     */
    public function lineas()
    {
        $lineas = [];

        foreach ($this->piezas() as $pieza) {
            if ($pieza['tipo'] === 'logo') {
                $lineas[] = self::MARCA_LOGO;
                continue;
            }

            if ($pieza['tipo'] === 'qr') {
                $lineas[] = self::MARCA_QR;
                continue;
            }

            $texto = '';
            foreach ($pieza['trozos'] as $trozo) {
                $texto .= $trozo['texto'];
            }

            $lineas[] = rtrim($texto, ' ');
        }

        return $lineas;
    }

    /**
     * Las piezas del ticket: la zona de arriba, la tabla y el pie (armadas una sola vez).
     *
     * @return array<int, array>
     */
    public function piezas()
    {
        if (is_null($this->piezas)) {
            $this->piezas = array_merge(
                $this->piezas_de_zona($this->diseno['superior']),
                $this->piezas_de_tabla(),
                $this->piezas_de_zona($this->diseno['pie'])
            );
        }

        return $this->piezas;
    }

    /**
     * Los bytes del QR: la MISMA secuencia `GS ( k` de qr() del Ticket 2.0 de siempre
     * (empresa-spa/src/mixins/sale/print_ticket/afip_qr_iva.js).
     *
     * @param string $link
     * @return string
     */
    public static function bytes_del_qr($link)
    {
        $largo = strlen($link) + 3;

        return "\x0A"
            ."\x1D\x28\x6B\x04\x00\x31\x41\x32\x00"
            ."\x1D\x28\x6B\x03\x00\x31\x43\x05"
            ."\x1D\x28\x6B\x03\x00\x31\x45\x30"
            ."\x1D\x28\x6B".chr($largo % 256).chr((int) floor($largo / 256))."\x31\x50\x30".$link
            ."\x1D\x28\x6B\x03\x00\x31\x51\x30"
            ."\x1D\x28\x6B\x03\x00\x31\x52\x30"
            ."\x0A"
            ."\x0A"
            ."\n";
    }

    /**
     * El comando `GS ! n` de un tamaño.
     *
     * @param string $tamano
     * @return string
     */
    public static function comando_de_tamano($tamano)
    {
        if ($tamano === TextoDeTicket::GRANDE) {
            return self::TAMANO_GRANDE;
        }

        if ($tamano === TextoDeTicket::ALTO) {
            return self::TAMANO_ALTO;
        }

        return self::TAMANO_NORMAL;
    }

    /**
     * Los bits del logo para la impresora, o null si no se pudo armar (el ticket sale sin logo, como
     * siempre). Protegido: un test lo reemplaza para no salir a internet.
     *
     * @param string $url
     * @return string|null
     */
    protected function raster_del_logo($url)
    {
        return SaleTicketRasterHelper::build_ticket_logo_raster($url, $this->ancho_mm);
    }

    // ── Zonas y cajas ─────────────────────────────────────────────────────────────────────────

    /**
     * Las piezas de una zona ("superior" o "pie"), fila por fila.
     *
     * @param array $items
     * @return array<int, array>
     */
    protected function piezas_de_zona($items)
    {
        $piezas = [];

        foreach ($this->filas($items) as $fila) {
            $de_la_fila = $fila['tipo'] === 'fijo'
                ? $this->piezas_de_fijo($fila['item'])
                : $this->piezas_de_fila($fila['cajas']);

            foreach ($de_la_fila as $pieza) {
                $piezas[] = $pieza;
            }
        }

        return $piezas;
    }

    /**
     * Las filas de una zona en la grilla de 12: las cajas se acomodan una al lado de la otra
     * mientras entran; un salto de fila corta; un bloque fijo va solo en su fila, a lo ancho.
     *
     * @param array $items
     * @return array<int, array> ['tipo' => 'cajas', 'cajas' => [['caja', 'cols']]] o ['tipo' => 'fijo', 'item']
     */
    protected function filas($items)
    {
        $filas = [];
        $cajas = [];
        $columna = 0;

        foreach ((array) $items as $item) {
            if (! is_array($item) || ! isset($item['tipo'])) {
                continue;
            }

            if ($item['tipo'] === DisenoDePaginaPdf::TIPO_SALTO_DE_FILA) {
                $this->cerrar_fila($filas, $cajas, $columna);
                continue;
            }

            if ($item['tipo'] === DisenoDePaginaPdf::TIPO_FIJO) {
                $this->cerrar_fila($filas, $cajas, $columna);
                $filas[] = ['tipo' => 'fijo', 'item' => $item];
                continue;
            }

            if ($item['tipo'] !== DisenoDePaginaPdf::TIPO_CAJA) {
                continue;
            }

            $cols = isset($item['cols']) ? max(1, min(12, (int) $item['cols'])) : 12;

            if ($columna + $cols > 12) {
                $this->cerrar_fila($filas, $cajas, $columna);
            }

            $cajas[] = ['caja' => $item, 'cols' => $cols];
            $columna += $cols;
        }

        $this->cerrar_fila($filas, $cajas, $columna);

        return $filas;
    }

    /**
     * Cierra la fila en curso (si tiene cajas) y arranca otra.
     *
     * @param array $filas
     * @param array $cajas
     * @param int   $columna
     * @return void
     */
    private function cerrar_fila(&$filas, &$cajas, &$columna)
    {
        if (count($cajas) > 0) {
            $filas[] = ['tipo' => 'cajas', 'cajas' => $cajas];
        }

        $cajas = [];
        $columna = 0;
    }

    /**
     * Las piezas de una fila de cajas: los logos primero y después los renglones, con las cajas lado
     * a lado.
     *
     * @param array $cajas [['caja', 'cols']]
     * @return array<int, array>
     */
    protected function piezas_de_fila($cajas)
    {
        $cantidad = count($cajas);
        $contenidos = [];
        $piezas = [];
        $alto = 0;

        foreach ($cajas as $i => $posicion) {
            $ancho = (int) floor($posicion['cols'] * $this->caracteres / 12);
            $ancho_de_contenido = $i < $cantidad - 1 ? $ancho - 1 : $ancho;

            $contenido = $this->contenido_de_caja($posicion['caja'], $ancho_de_contenido);
            $contenido['ancho'] = max(0, $ancho_de_contenido);
            $contenidos[] = $contenido;

            if (! is_null($contenido['logo'])) {
                $piezas[] = PiezasDeTicket::logo($contenido['logo']);
            }

            $alto = max($alto, count($contenido['renglones']));
        }

        for ($k = 0; $k < $alto; $k++) {
            $trozos = [];

            foreach ($contenidos as $i => $contenido) {
                $del_renglon = isset($contenido['renglones'][$k]) ? $contenido['renglones'][$k] : [];

                foreach (PiezasDeTicket::alinear($del_renglon, $contenido['ancho'], 'izquierda') as $trozo) {
                    $trozos[] = $trozo;
                }

                if ($i < $cantidad - 1) {
                    $trozos[] = PiezasDeTicket::espacios(1);
                }
            }

            $piezas[] = PiezasDeTicket::renglon(PiezasDeTicket::sin_relleno_al_final($trozos));
        }

        return $piezas;
    }

    /**
     * El contenido de una caja: el logo (si tiene uno con imagen) y sus renglones, cada uno una lista
     * de trozos que ocupa como mucho $ancho columnas. Una caja sin ningún campo con datos no tiene
     * renglones (ni el título ni la línea).
     *
     * @param array $caja
     * @param int   $ancho columnas del contenido.
     * @return array{logo: string|null, renglones: array<int, array>}
     */
    protected function contenido_de_caja($caja, $ancho)
    {
        $vacio = ['logo' => null, 'renglones' => []];

        if ($ancho < 1) {
            return $vacio;
        }

        $logo = null;
        $renglones_de_campos = [];
        $campos = isset($caja['campos']) && is_array($caja['campos']) ? $caja['campos'] : [];

        foreach ($campos as $campo) {
            $key = isset($campo['key']) ? $campo['key'] : null;

            /** Una key que el catálogo de ticket no conoce se saltea sin error (como en la hoja). */
            if (! is_string($key) || ! isset($this->catalogo[$key])) {
                continue;
            }

            if ($key === CatalogoDeCamposPdf::KEY_NEGOCIO_LOGO) {
                if (is_null($logo)) {
                    $logo = $this->logo_del_campo($campo);
                }
                continue;
            }

            foreach ($this->renglones_de_campo($key, $campo, $ancho) as $renglon) {
                $renglones_de_campos[] = $renglon;
            }
        }

        if (count($renglones_de_campos) === 0 && is_null($logo)) {
            return $vacio;
        }

        $renglones = [];

        $titulo = trim(TextoDeTicket::limpiar(isset($caja['titulo']) ? $caja['titulo'] : ''));
        if ($titulo !== '') {
            foreach (PiezasDeTicket::texto_partido($titulo, $ancho, true) as $renglon) {
                $renglones[] = PiezasDeTicket::sin_relleno_al_final($renglon);
            }
        }

        foreach ($renglones_de_campos as $renglon) {
            $renglones[] = $renglon;
        }

        /** "Con línea" (borde; gris en un ticket es lo mismo) → guiones abajo; "Sin línea" → nada. */
        $estilo = isset($caja['estilo']) ? $caja['estilo'] : DisenoDePaginaPdf::ESTILO_DE_CAJA_POR_DEFECTO;
        if ($estilo !== 'ninguno') {
            $renglones[] = PiezasDeTicket::guiones($ancho);
        }

        return ['logo' => $logo, 'renglones' => $renglones];
    }

    /**
     * Los bits del logo de un campo `negocio_logo`, o null sin logo cargado (o si no se pudo armar).
     *
     * @param array $campo
     * @return string|null
     */
    private function logo_del_campo($campo)
    {
        $url = $this->valor(CatalogoDeCamposPdf::KEY_NEGOCIO_LOGO, $campo);

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $raster = $this->raster_del_logo($url);

        return (is_string($raster) && $raster !== '') ? $raster : null;
    }

    /**
     * Los renglones de un campo en una caja de $ancho columnas, ya alineados ([] si no tiene valor).
     *
     * @param string $key
     * @param array  $campo
     * @param int    $ancho
     * @return array<int, array>
     */
    protected function renglones_de_campo($key, $campo, $ancho)
    {
        $valor = $this->valor($key, $campo);

        $elementos = is_array($valor) ? array_values($valor) : [$valor];
        $elementos = array_values(array_filter($elementos, function ($elemento) {
            return ! is_null($elemento) && trim(TextoDeTicket::limpiar($elemento)) !== '';
        }));

        if (count($elementos) === 0) {
            return [];
        }

        $estilo = $this->estilo_del_campo($key, $campo);
        $tamano = TextoDeTicket::clase_de_tamano($estilo['tamano']);

        /** Grande que no entra ni con una letra en la caja: sale normal. */
        if (floor($ancho / TextoDeTicket::factor($tamano)) < 1) {
            $tamano = TextoDeTicket::NORMAL;
        }

        $rotulo = $this->rotulo($key, $campo);
        $renglones = [];

        foreach ($elementos as $i => $elemento) {
            $texto = TextoDeTicket::limpiar($elemento, true);

            if ($i === 0 && $rotulo !== '') {
                $texto = $rotulo.$texto;
            }

            foreach (PiezasDeTicket::texto_partido($texto, $ancho, $estilo['negrita'], $tamano, $estilo['alineacion']) as $renglon) {
                $renglones[] = PiezasDeTicket::sin_relleno_al_final($renglon);
            }
        }

        return $renglones;
    }

    /**
     * Valor de un campo, pedido UNA vez a la fuente.
     *
     * @param string $key
     * @param array  $campo
     * @return mixed
     */
    protected function valor($key, $campo)
    {
        $clave = $key.'|'.(isset($campo['id']) ? (string) $campo['id'] : '');

        if (! array_key_exists($clave, $this->valores)) {
            $this->valores[$clave] = $this->fuente->valor($key, $campo);
        }

        return $this->valores[$clave];
    }

    /**
     * El rótulo ("Cliente: "), o '' sin rótulo: null en el diseño = el del catálogo.
     *
     * @param string $key
     * @param array  $campo
     * @return string
     */
    protected function rotulo($key, $campo)
    {
        $etiqueta = array_key_exists('etiqueta', $campo) && ! is_null($campo['etiqueta'])
            ? (string) $campo['etiqueta']
            : (string) $this->catalogo[$key]['etiqueta'];

        $etiqueta = trim(TextoDeTicket::limpiar($etiqueta));

        return $etiqueta === '' ? '' : $etiqueta.': ';
    }

    /**
     * El estilo efectivo de un campo: lo del diseño y, lo que viene en null, lo del catálogo. La
     * cursiva no existe en una comandera.
     *
     * @param string $key
     * @param array  $campo
     * @return array{tamano: int, negrita: bool, alineacion: string}
     */
    protected function estilo_del_campo($key, $campo)
    {
        $defecto = $this->catalogo[$key]['estilo'];

        $alineacion = isset($campo['alineacion']) && in_array($campo['alineacion'], DisenoDePaginaPdf::ALINEACIONES, true)
            ? $campo['alineacion']
            : $defecto['alineacion'];

        return [
            'tamano' => isset($campo['tamano']) && is_numeric($campo['tamano']) ? (int) $campo['tamano'] : (int) $defecto['tamano'],
            'negrita' => isset($campo['negrita']) && ! is_null($campo['negrita']) ? (bool) $campo['negrita'] : (bool) $defecto['negrita'],
            'alineacion' => $alineacion,
        ];
    }

    /**
     * Las piezas de un bloque fijo de ARCA (solo en una factura con CAE).
     *
     * @param array $item
     * @return array<int, array>
     */
    protected function piezas_de_fijo($item)
    {
        if (! $this->es_fiscal) {
            return [];
        }

        $bloques = new BloquesFiscalesDeTicket(
            $this->sale,
            $this->afip_ticket,
            $this->afip_helper,
            $this->caracteres,
            (bool) $this->perfil->use_current_date
        );

        return $bloques->piezas($item);
    }

    // ── Tabla ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Las piezas de la tabla de artículos: encabezado, línea, un bloque de renglones por ítem y
     * línea al final. Sin columnas visibles no hay tabla.
     *
     * @return array<int, array>
     */
    protected function piezas_de_tabla()
    {
        $columnas = $this->columnas_de_la_tabla();

        if (count($columnas) === 0) {
            return [];
        }

        $cantidad = count($columnas);

        /** Precarga marca/categoría/subcategoría/proveedor para las columnas de relación (como el PDF). */
        PdfColumnService::eager_load_sale_article_relations($this->sale);

        /** Los MISMOS ítems y valores que la tabla del PDF con cajas. */
        $filas = [];
        $index = 1;
        foreach ($this->fuente->totales()->renglones() as $renglon) {
            $valores = [];
            foreach ($columnas as $c => $columna) {
                $valores[$c] = trim(TextoDeTicket::limpiar($this->valor_de_columna($columna, $index, $renglon[1])));
            }
            $filas[] = $valores;
            $index++;
        }

        /** Una columna es numérica si todos sus valores con algo lo son (y tiene al menos uno). */
        $numericas = [];
        foreach ($columnas as $c => $columna) {
            $con_algo = 0;
            $numericos = 0;
            foreach ($filas as $valores) {
                if ($valores[$c] === '') {
                    continue;
                }
                $con_algo++;
                if (self::es_numerico($valores[$c])) {
                    $numericos++;
                }
            }
            $numericas[$c] = $con_algo > 0 && $con_algo === $numericos;
        }

        $piezas = [];

        /** Encabezado: los rótulos en negrita, cortados al ancho de su columna. */
        $encabezado = [];
        foreach ($columnas as $c => $columna) {
            $rotulo = TextoDeTicket::cortar(trim(TextoDeTicket::limpiar($columna['label'])), $columna['contenido']);
            foreach (PiezasDeTicket::alinear([PiezasDeTicket::trozo($rotulo, true)], $columna['contenido'], $numericas[$c] ? 'derecha' : 'izquierda') as $trozo) {
                $encabezado[] = $trozo;
            }
            if ($c < $cantidad - 1) {
                $encabezado[] = PiezasDeTicket::espacios(1);
            }
        }
        $piezas[] = PiezasDeTicket::renglon(PiezasDeTicket::sin_relleno_al_final($encabezado));
        $piezas[] = PiezasDeTicket::renglon(PiezasDeTicket::guiones($this->caracteres));

        foreach ($filas as $valores) {
            foreach ($this->renglones_de_fila_de_tabla($columnas, $valores) as $trozos) {
                $piezas[] = PiezasDeTicket::renglon($trozos);
            }
        }

        $piezas[] = PiezasDeTicket::renglon(PiezasDeTicket::guiones($this->caracteres));

        return $piezas;
    }

    /**
     * Los renglones de un ítem de la tabla: cada columna partida (con salto de línea), cortada (sin
     * él) o, si es un número que no entra, seguida abajo; el ítem ocupa los renglones de su columna
     * más alta.
     *
     * @param array $columnas
     * @param array $valores
     * @return array<int, array> renglones de trozos.
     */
    private function renglones_de_fila_de_tabla($columnas, $valores)
    {
        $cantidad = count($columnas);
        $partes = [];
        $alto = 1;

        foreach ($columnas as $c => $columna) {
            $valor = $valores[$c];
            $ancho = $columna['contenido'];

            if ($ancho < 1) {
                $partes[$c] = [''];
            } elseif ($columna['wrap_content']) {
                $partes[$c] = TextoDeTicket::partir($valor, $ancho);
            } elseif (TextoDeTicket::largo($valor) > $ancho && self::es_numerico($valor)) {
                $partes[$c] = TextoDeTicket::partir_por_caracteres($valor, $ancho);
            } else {
                $partes[$c] = [TextoDeTicket::cortar($valor, $ancho)];
            }

            $alto = max($alto, count($partes[$c]));
        }

        $renglones = [];
        for ($k = 0; $k < $alto; $k++) {
            $trozos = [];

            foreach ($columnas as $c => $columna) {
                $texto = isset($partes[$c][$k]) ? $partes[$c][$k] : '';
                $alineacion = self::es_numerico($valores[$c]) ? 'derecha' : 'izquierda';

                foreach (PiezasDeTicket::alinear([PiezasDeTicket::trozo($texto, false)], max(0, $columna['contenido']), $alineacion) as $trozo) {
                    $trozos[] = $trozo;
                }

                if ($c < $cantidad - 1) {
                    $trozos[] = PiezasDeTicket::espacios(1);
                }
            }

            $renglones[] = PiezasDeTicket::sin_relleno_al_final($trozos);
        }

        return $renglones;
    }

    /**
     * Las columnas visibles del perfil, en su orden, con su ancho en caracteres (ver el docblock de
     * la clase: medias columnas de 24 sobre el ancho del rollo, lo que sobra a la de salto de línea
     * o a la más ancha). Si no entran todas (más columnas que caracteres), se achica la más ancha y,
     * en el extremo, se dejan afuera las últimas.
     *
     * @return array<int, array{label: string, value_resolver: string, wrap_content: bool, caracteres: int, contenido: int}>
     */
    public function columnas_de_la_tabla()
    {
        if (! $this->perfil->relationLoaded('pdf_column_options')) {
            $this->perfil->load('pdf_column_options');
        }

        $columnas = [];
        foreach ($this->perfil->pdf_column_options as $option) {
            $visible = isset($option->pivot->visible) ? (bool) $option->pivot->visible : true;
            $ancho_mm = isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width;

            if (! $visible || $ancho_mm <= 0) {
                continue;
            }

            $medias = max(1, (int) round($ancho_mm * CatalogoDeCamposPdf::GRILLA_DE_TABLA / $this->ancho_mm));

            $columnas[] = [
                'label' => (string) $option->label,
                'value_resolver' => (string) $option->value_resolver,
                'order' => isset($option->pivot->order) ? (int) $option->pivot->order : 0,
                'wrap_content' => isset($option->pivot->wrap_content) ? (bool) $option->pivot->wrap_content : false,
                'caracteres' => (int) floor($medias * $this->caracteres / CatalogoDeCamposPdf::GRILLA_DE_TABLA),
            ];
        }

        usort($columnas, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        $columnas = $this->repartir_caracteres($columnas);

        $cantidad = count($columnas);
        foreach ($columnas as $c => $columna) {
            $columnas[$c]['contenido'] = $c < $cantidad - 1 ? $columna['caracteres'] - 1 : $columna['caracteres'];
        }

        return $columnas;
    }

    /**
     * Ajusta los caracteres de las columnas para que sumen exactamente N.
     *
     * @param array $columnas
     * @return array
     */
    private function repartir_caracteres($columnas)
    {
        if (count($columnas) === 0) {
            return $columnas;
        }

        $suma = 0;
        foreach ($columnas as $columna) {
            $suma += $columna['caracteres'];
        }

        /** Sobra: a la primera con salto de línea; si no hay, a la más ancha. */
        if ($suma < $this->caracteres) {
            $destino = null;
            foreach ($columnas as $c => $columna) {
                if ($columna['wrap_content']) {
                    $destino = $c;
                    break;
                }
            }
            if (is_null($destino)) {
                $destino = $this->mas_ancha($columnas);
            }
            $columnas[$destino]['caracteres'] += $this->caracteres - $suma;

            return $columnas;
        }

        /** Falta: se le saca de a uno a la más ancha mientras pueda. */
        while ($suma > $this->caracteres) {
            $mas_ancha = $this->mas_ancha($columnas);

            if ($columnas[$mas_ancha]['caracteres'] <= 1) {
                /** Todas en 1 y siguen sin entrar: la última queda afuera. */
                $sacada = array_pop($columnas);
                $suma -= $sacada['caracteres'];
                continue;
            }

            $columnas[$mas_ancha]['caracteres']--;
            $suma--;
        }

        return $columnas;
    }

    /**
     * El índice de la columna con más caracteres (la primera, si hay empate).
     *
     * @param array $columnas
     * @return int
     */
    private function mas_ancha($columnas)
    {
        $mas_ancha = 0;
        foreach ($columnas as $c => $columna) {
            if ($columna['caracteres'] > $columnas[$mas_ancha]['caracteres']) {
                $mas_ancha = $c;
            }
        }

        return $mas_ancha;
    }

    /**
     * El valor de una celda, con el mismo contexto que le arma el PDF con cajas
     * (SaleLayoutPdf::valor_de_columna()).
     *
     * @param array  $columna
     * @param int    $index
     * @param object $item
     * @return mixed
     */
    private function valor_de_columna($columna, $index, $item)
    {
        return PdfColumnService::resolve_value($columna['value_resolver'], [
            'item' => $item,
            'index' => $index,
            'sale' => $this->sale,
            'afip_ticket' => $this->afip_ticket,
            'afip_helper' => $this->afip_helper,
            'numbers' => Numbers::class,
            'general_helper' => GeneralHelper::class,
        ]);
    }

    /**
     * ¿El valor es un número (cantidad, precio, porcentaje)? "12", "1.234,50", "$1.234", "-10%",
     * "USD 12,00".
     *
     * @param string $valor
     * @return bool
     */
    public static function es_numerico($valor)
    {
        return (bool) preg_match('/^[\-\+]?\s*(?:US\$|U\$S|USD|\$)?\s*-?\d[\d\.,]*\s*%?$/u', trim((string) $valor));
    }

    /**
     * El ancho del rollo: el pedido; si no, el de su tipo de hoja; si no, el del papel guardado; si
     * no, 80.
     *
     * @param PdfColumnProfile $perfil
     * @param int|null         $ancho_mm
     * @return int
     */
    private function resolver_ancho_mm(PdfColumnProfile $perfil, $ancho_mm)
    {
        if ((int) $ancho_mm > 0) {
            return (int) $ancho_mm;
        }

        if (! empty($perfil->sheet_type_id) && $perfil->sheet_type && (int) $perfil->sheet_type->width > 0) {
            return (int) $perfil->sheet_type->width;
        }

        if ((int) $perfil->paper_width_mm > 0) {
            return (int) $perfil->paper_width_mm;
        }

        return self::ANCHO_POR_DEFECTO_MM;
    }
}
