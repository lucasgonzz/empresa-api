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

