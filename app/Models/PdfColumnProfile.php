<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfColumnProfile extends Model
{
    protected $guarded = [];

    /**
     * Eager loading estándar vía fullModel(); ampliar con ->with() cuando haga falta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        /**
         * Necesario para fullModel() y respuestas API: sin esto el JSON no trae pivots y el front queda desactualizado.
         */
        return $query->with([
            'sheet_type',
            'pdf_column_options' => function ($relation) {
                $relation->orderByPivot('order', 'asc');
            },
        ]);
    }

    protected $casts = [
        'columns' => 'array',
        'is_default' => 'boolean',
        /**
         * Perfil predeterminado para enlaces de comprobante enviados por WhatsApp (remitos).
         */
        'is_default_whatsapp' => 'boolean',
        /**
         * Perfil predeterminado para WhatsApp cuando la venta tiene factura ARCA.
         */
        'is_default_whatsapp_afip' => 'boolean',
        /**
         * Perfil predeterminado para impresiones de PDF solicitadas desde la tienda (remito o factura según corresponda).
         */
        'is_default_tienda' => 'boolean',
        'is_afip_ticket' => 'boolean',
        'show_totals_on_each_page' => 'boolean',
        'show_comissions' => 'boolean',
        'show_total_costs' => 'boolean',
        /**
         * Flag de visibilidad del total general en el pie del PDF.
         */
        'show_total_in_footer' => 'boolean',
        /**
         * Flag de visibilidad de la línea "Sub Total" en el pie del PDF.
         * Solo tiene efecto cuando hay descuentos/recargos.
         */
        'show_subtotal_in_footer' => 'boolean',
        /**
         * Flag para imprimir la fecha actual en lugar de la fecha del comprobante.
         */
        'use_current_date' => 'boolean',
        'margin_mm' => 'integer',
        /**
         * Tamaño del logo en mm por perfil. Null = usar el tamaño global del dueño (users.pdf_image_size).
         */
        'logo_size_mm' => 'integer',
        /**
         * Tamaño de letra uniforme (pt) para encabezados de columnas en PDF tabular de artículos.
         */
        'table_header_font_size' => 'integer',
        /**
         * Diseño del header por cuadrante (qué propiedades se muestran y en qué orden).
         * Null = el render usa el default por código (ver default_header_layout()).
         * El tamaño del logo NO va acá: sigue en logo_size_mm.
         *
         * Esquema:
         * {
         *   "emisor": {
         *     "izquierda": ["razon_social", "domicilio_comercial", "condicion_iva"],
         *     "derecha": ["numero_comprobante", "fecha_emision", "cuit", "ingresos_brutos", "inicio_actividades"]
         *   },
         *   "receptor": {
         *     "izquierda": ["cliente_nombre", "cliente_telefono", "cliente_localidad", "cliente_direccion", "cliente_cuit"]
         *   }
         * }
         *
         * Catálogo de claves válidas (fuente de verdad compartida con el render y el diseñador visual):
         * - Emisor (ubicables en izquierda/derecha): razon_social, domicilio_comercial, condicion_iva,
         *   punto_venta (solo perfiles fiscales), cuit, ingresos_brutos, inicio_actividades,
         *   numero_comprobante, fecha_emision, web, telefono, email.
         * - Estructural fijo (NO va en el JSON, no se puede mover): nombre del negocio (título 18pt),
         *   recuadro de la letra, logo.
         * - Receptor izquierda (remito negro, ubicables/ordenables): cliente_nombre, cliente_telefono,
         *   cliente_localidad, cliente_direccion, cliente_cuit, cliente_condicion_iva,
         *   vendedor (nombre del seller de la venta), empleado (nombre del employee que cargó la venta).
         * - Receptor derecha (remito negro): reservado a cuenta corriente, fijo, no configurable.
         * - Perfiles fiscales (AFIP): el bloque receptor es fijo por requisito fiscal (no configurable).
         *   En el emisor, los campos fiscales obligatorios (razon_social, domicilio_comercial,
         *   condicion_iva, punto_venta, cuit, ingresos_brutos, inicio_actividades, numero_comprobante,
         *   fecha_emision) no se pueden ocultar; solo se pueden sumar/ordenar web/telefono/email.
         */
        'header_layout' => 'array',
        /**
         * Diseño del encabezado del PDF del catálogo de artículos (perfiles con model_name
         * 'article'). Null = sin diseño: el catálogo se imprime como siempre (banner opcional,
         * barra de título y columnas), sin logo ni datos del negocio.
         *
         * Es una columna aparte de header_layout a propósito: aquélla guarda el esquema
         * emisor/receptor de los PDF de venta (AfipPdfHelper), y un perfil puede cambiar de
         * model_name por update(); compartir columna dejaría al render de venta leyendo un
         * esquema ajeno.
         *
         * Esquema (lo que persiste CatalogHeaderLayoutHelper::normalize(); claves fijas, nada más):
         * {
         *   "logo":         { "show": true, "pages": "all", "size_mm": 25 },
         *   "company_name": { "show": true },
         *   "rows_pages":   "all",
         *   "izquierda":    [ { "title": "Teléfono", "value": "11 5555-5555", "source": "telefono" } ],
         *   "derecha":      [ { "title": "Horario",  "value": "Lun a Vie 9 a 18", "source": null } ]
         * }
         *
         * - logo.pages y rows_pages: 'all' (todas las hojas) o 'first' (solo la primera).
         * - logo.size_mm: entero entre 10 y 60 (lado mayor del logo, proporción real).
         * - Renglones: hasta 15 por columna; title hasta 60 caracteres, value hasta 200.
         *   source es una clave de CatalogHeaderLayoutHelper::SOURCE_LABELS (el valor se
         *   reemplaza por el dato actual del negocio al imprimir) o null (renglón libre).
         * - Lo que la SPA usa para el drag & drop (uid, etc.) no se persiste.
         */
        'catalog_header_layout' => 'array',
        /**
         * Flag para mostrar u ocultar las observaciones del cliente (clients.description)
         * en el PDF de venta. Default true: mantiene el comportamiento legacy (se imprimen
         * siempre que el cliente tenga observaciones cargadas).
         */
        'show_client_description' => 'boolean',
        /**
         * Diseño de la hoja armado en el diseñador de PDF (misión diseno-pdf-configurable,
         * 1/10/2026): las cajas de arriba de la tabla ("superior") y las del pie ("pie"), con sus
         * campos y su estilo. Esquema, límites y normalización en
         * App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf; catálogo de campos en
         * CatalogoDeCamposPdf.
         *
         * 🔴 NULL NO ES "VACÍO": es "este diseño nunca se armó en el diseñador", y el PDF sale
         * exactamente como siempre (NewSalePdf / ProfileDocumentPdf de antes, con los flags show_*).
         * Un diseño con page_layout ignora esos flags: lo que se imprime lo dicen las cajas.
         */
        'page_layout' => 'array',
        /**
         * Alto de la hoja en mm. Solo lo usa el PDF con page_layout; null = 297 (A4).
         */
        'paper_height_mm' => 'integer',
    ];

    /**
     * Relación principal: perfil con múltiples opciones configurables.
     */
    public function pdf_column_options()
    {
        return $this->belongsToMany(PdfColumnOption::class, 'pdf_column_option_profile')
            ->withPivot(['visible', 'order', 'width', 'wrap_content', 'font_size', 'text_align'])
            ->withTimestamps();
    }

    /**
     * Tipo de hoja asociado al perfil para determinar dimensiones de impresión.
     */
    public function sheet_type()
    {
        return $this->belongsTo(SheetType::class);
    }

    /**
     * Solo los perfiles de HOJA: sin tipo de hoja, o con un tipo de hoja que tiene alto (misión
     * diseno-ticket-comandera, 9/10/2026, decisiones D2 y D12).
     *
     * 🔴 Es la guarda de la decisión D4: un perfil de ticket (rollo de comandera) se imprime directo
     * en la comandera, NUNCA se dibuja como PDF. Toda consulta que elige un perfil para un PDF
     * (el por defecto, el de WhatsApp, el de la tienda, el del asistente) pasa por acá.
     *
     * Subconsulta y no join: no cambia las columnas que trae la consulta (un join traería las de
     * sheet_types y pisaría el id del perfil). El where agrupado no se come otras condiciones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeHoja($query)
    {
        return $query->where(function ($sub) {
            $sub->whereNull('pdf_column_profiles.sheet_type_id')
                ->orWhereIn('pdf_column_profiles.sheet_type_id', function ($tipos) {
                    $tipos->select('id')->from('sheet_types')->whereNotNull('height');
                });
        });
    }

    /**
     * Solo los perfiles de TICKET: con un tipo de hoja sin alto (rollo de comandera, decisión D2).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeTicket($query)
    {
        return $query->whereIn('pdf_column_profiles.sheet_type_id', function ($tipos) {
            $tipos->select('id')->from('sheet_types')->whereNull('height');
        });
    }

    /**
     * ¿Es un perfil de ticket de comandera? Lo dice su tipo de hoja (alto NULL, decisión D2). Sin
     * tipo de hoja es hoja (D12: un diseño creado desde el ABM viejo quedaba sin tipo).
     *
     * @return bool
     */
    public function es_ticket()
    {
        if (is_null($this->sheet_type_id)) {
            return false;
        }

        $sheet_type = $this->sheet_type;

        return ! is_null($sheet_type) && $sheet_type->es_ticket();
    }

    /**
     * Layout de header por defecto (usado por el render cuando header_layout es null).
     * Centraliza el default para que el generador de PDF (prompt 439) y el diseñador visual
     * (prompt 441) partan siempre de la misma estructura.
     *
     * @param  bool  $is_afip  Si el perfil es fiscal (ARCA): agrega 'punto_venta' en emisor.derecha.
     * @return array<string, array<string, array<int, string>>>
     */
    public static function default_header_layout($is_afip)
    {
        // Emisor: identidad (razón social, domicilio, condición IVA) a la izquierda;
        // datos del comprobante y fiscales a la derecha.
        $emisor_derecha = [
            'numero_comprobante',
            'fecha_emision',
            'cuit',
            'ingresos_brutos',
            'inicio_actividades',
        ];

        // En perfiles fiscales (ARCA) el punto de venta es un dato obligatorio del emisor:
        // se agrega al final del bloque derecho del emisor.
        if ($is_afip) {
            $emisor_derecha[] = 'punto_venta';
        }

        return [
            'emisor' => [
                'izquierda' => ['razon_social', 'domicilio_comercial', 'condicion_iva'],
                'derecha' => $emisor_derecha,
            ],
            // Receptor: solo aplica al remito negro (izquierda configurable);
            // la derecha queda reservada a cuenta corriente (fija, no configurable acá).
            'receptor' => [
                'izquierda' => [
                    'cliente_nombre',
                    'cliente_telefono',
                    'cliente_localidad',
                    'cliente_direccion',
                    'cliente_cuit',
                ],
            ],
        ];
    }
}

