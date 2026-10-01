<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

/**
 * Catálogo de los campos que el diseñador de PDF ofrece para armar las cajas de un diseño
 * (misión diseno-pdf-configurable, 1/10/2026).
 *
 * ES LA ÚNICA FUENTE DE VERDAD DEL CATÁLOGO, para las dos puntas:
 * - el SPA lo recibe entero por `GET api/pdf-column-profiles/page-layout-catalog` (no tiene
 *   una copia propia: la bandeja del diseñador, los nombres, los ejemplos y los estilos por
 *   defecto salen de acá);
 * - el PDF resuelve el valor de cada `key` con su fuente de campos (`Campos*Pdf`). El test
 *   "cada campo del catálogo se resuelve" recorre este catálogo y le pide el valor a la fuente:
 *   un campo agregado acá sin su resolver tiene que dar rojo.
 *
 * 🔴 Las `key` se persisten en `pdf_column_profiles.page_layout` de cada cliente: una key NO se
 * renombra nunca. Si un campo se retira, se saca de acá y el PDF la saltea (no se rompe).
 *
 * Cada campo:
 * - key           identificador (patrón DisenoDePaginaPdf::PATRON_KEY).
 * - categoria     key de una de las categorías del modelo (ver categorias()).
 * - nombre        cómo se llama en la bandeja del diseñador (puede ser más largo que la etiqueta).
 * - etiqueta      rótulo que se imprime delante del valor ("Cliente: Juan"). '' = sin rótulo.
 * - tipo          'texto' (un renglón), 'texto_largo' (puede ocupar varios renglones) o 'lista'
 *                 (varios renglones: uno por método de pago, por descuento, etc.).
 * - ejemplo       valor de muestra para la vista previa del diseñador (string, o array en 'lista').
 * - aparece_cuando cuándo sale en el PDF, si no sale siempre (texto para el usuario), o null.
 * - repetible     true solo para el texto libre: se puede poner varias veces (cada uno con su id).
 * - zona_sugerida 'superior' o 'pie': donde suele ir (el diseñador lo usa para "Agregar").
 * - estilo        estilo por defecto: tamano (pt), negrita, cursiva, alineacion.
 */
class CatalogoDeCamposPdf
{
    /** Modelos de perfil que se diseñan con cajas. 'article' (catálogo de artículos) no. */
    const MODELOS = ['sale', 'budget', 'order'];

    const TIPO_TEXTO = 'texto';
    const TIPO_TEXTO_LARGO = 'texto_largo';
    const TIPO_LISTA = 'lista';

    /** El único campo repetible: el texto que escribe el usuario. Se guarda con `id` y `texto`. */
    const KEY_TEXTO_LIBRE = 'texto_libre';

    /** Bloques fijos de la factura de ARCA (solo `sale` con is_afip_ticket): se mueven, no se sacan. */
    const FIJO_AFIP_RECEPTOR = 'afip_receptor';
    const FIJO_AFIP_PIE = 'afip_pie';

    /** Ejemplos de plata que se repiten en varios campos. */
    const EJEMPLO_SALDO_ANTERIOR = '$15.000';
    const DATO_INTERNO = 'Dato interno: no lo pongas en el comprobante que recibe el cliente.';

    /**
     * ¿El modelo de perfil se diseña con cajas?
     *
     * @param string $model_name
     * @return bool
     */
    public static function soporta($model_name)
    {
        return in_array($model_name, self::MODELOS, true);
    }

    /**
     * Categorías de la bandeja del diseñador, en el orden en que se muestran.
     *
     * @param string $model_name 'sale' | 'budget' | 'order'
     * @return array<int, array<string, string>> [{key, nombre, icono}] (icono = clase de Bootstrap Icons)
     */
    public static function categorias($model_name)
    {
        if ($model_name === 'sale') {
            return [
                ['key' => 'cliente', 'nombre' => 'Cliente', 'icono' => 'bi-person'],
                ['key' => 'venta', 'nombre' => 'Venta', 'icono' => 'bi-receipt'],
                ['key' => 'cuenta_corriente', 'nombre' => 'Cuenta corriente', 'icono' => 'bi-journal-text'],
                ['key' => 'totales', 'nombre' => 'Totales', 'icono' => 'bi-calculator'],
                ['key' => 'otros', 'nombre' => 'Otros', 'icono' => 'bi-three-dots'],
            ];
        }

        if ($model_name === 'budget') {
            return [
                ['key' => 'cliente', 'nombre' => 'Cliente', 'icono' => 'bi-person'],
                ['key' => 'presupuesto', 'nombre' => 'Presupuesto', 'icono' => 'bi-file-earmark-text'],
                ['key' => 'totales', 'nombre' => 'Totales', 'icono' => 'bi-calculator'],
                ['key' => 'otros', 'nombre' => 'Otros', 'icono' => 'bi-three-dots'],
            ];
        }

        if ($model_name === 'order') {
            return [
                ['key' => 'comprador', 'nombre' => 'Comprador', 'icono' => 'bi-person'],
                ['key' => 'pedido', 'nombre' => 'Pedido', 'icono' => 'bi-bag'],
                ['key' => 'totales', 'nombre' => 'Totales', 'icono' => 'bi-calculator'],
                ['key' => 'otros', 'nombre' => 'Otros', 'icono' => 'bi-three-dots'],
            ];
        }

        return [];
    }

    /**
     * Todos los campos que el diseñador ofrece para un modelo, en el orden de la bandeja.
     *
     * @param string $model_name 'sale' | 'budget' | 'order'
     * @return array<int, array<string, mixed>>
     */
    public static function campos($model_name)
    {
        if ($model_name === 'sale') {
            return array_merge(
                self::campos_de_cliente('la venta'),
                self::campos_de_venta(),
                self::campos_de_cuenta_corriente(),
                self::campos_de_totales_de_venta(),
                self::campos_otros()
            );
        }

        if ($model_name === 'budget') {
            return array_merge(
                self::campos_de_cliente('el presupuesto'),
                self::campos_de_presupuesto(),
                self::campos_de_totales_de_presupuesto(),
                self::campos_otros()
            );
        }

        if ($model_name === 'order') {
            return array_merge(
                self::campos_de_comprador(),
                self::campos_de_pedido(),
                self::campos_de_totales_de_pedido(),
                self::campos_otros()
            );
        }

        return [];
    }

    /**
     * Definición de un campo, o null si el modelo no lo tiene.
     *
     * @param string $model_name
     * @param string $key
     * @return array<string, mixed>|null
     */
    public static function campo($model_name, $key)
    {
        foreach (self::campos($model_name) as $campo) {
            if ($campo['key'] === $key) {
                return $campo;
            }
        }

        return null;
    }

    /**
     * Las keys de los campos de un modelo.
     *
     * @param string $model_name
     * @return array<int, string>
     */
    public static function keys($model_name)
    {
        $keys = [];
        foreach (self::campos($model_name) as $campo) {
            $keys[] = $campo['key'];
        }

        return $keys;
    }

    /**
     * Estilo por defecto de un campo (el del catálogo, o el genérico si la key no existe).
     *
     * @param string $model_name
     * @param string $key
     * @return array{tamano:int, negrita:bool, cursiva:bool, alineacion:string}
     */
    public static function estilo_por_defecto($model_name, $key)
    {
        $campo = self::campo($model_name, $key);

        return $campo ? $campo['estilo'] : self::estilo();
    }

    /**
     * Bloques fijos que el diseño tiene que llevar, con su zona. Solo la factura de ARCA los tiene:
     * el bloque del cliente que pide ARCA (arriba) y el cuadro de importes + QR + CAE (en el pie).
     * Se pueden mover dentro de su zona, pero no sacar (DisenoDePaginaPdf::asegurar_fijos).
     *
     * @param string $model_name
     * @param bool   $es_fiscal  is_afip_ticket del perfil.
     * @return array<int, array<string, mixed>> [{key, zona, nombre, descripcion}]
     */
    public static function fijos($model_name, $es_fiscal)
    {
        if ($model_name !== 'sale' || ! $es_fiscal) {
            return [];
        }

        return [
            [
                'key' => self::FIJO_AFIP_RECEPTOR,
                'zona' => 'superior',
                'nombre' => 'Datos del cliente para ARCA',
                'descripcion' => 'CUIT o DNI, condición frente al IVA, condición de venta, nombre y domicilio. Lo pide ARCA: se puede mover, pero no sacar.',
            ],
            [
                'key' => self::FIJO_AFIP_PIE,
                'zona' => 'pie',
                'nombre' => 'Importes, QR y CAE de ARCA',
                'descripcion' => 'El cuadro de importes, el código QR y el CAE de la factura. Lo pide ARCA: se puede mover, pero no sacar.',
            ],
        ];
    }

    /**
     * Formatos de hoja que ofrece el diseñador (siempre vertical). El ancho va a
     * paper_width_mm / printable_width_mm y el alto a paper_height_mm.
     *
     * @return array<int, array<string, mixed>> [{key, nombre, ancho_mm, alto_mm}]
     */
    public static function formatos_de_hoja()
    {
        return [
            ['key' => 'a4', 'nombre' => 'A4', 'ancho_mm' => 210, 'alto_mm' => 297],
            ['key' => 'carta', 'nombre' => 'Carta', 'ancho_mm' => 216, 'alto_mm' => 279],
            ['key' => 'oficio', 'nombre' => 'Oficio', 'ancho_mm' => 216, 'alto_mm' => 356],
            ['key' => 'a5', 'nombre' => 'A5', 'ancho_mm' => 148, 'alto_mm' => 210],
        ];
    }

    // ── Bloques del catálogo ──────────────────────────────────────────────────────────────────

    /**
     * Datos del cliente (venta y presupuesto: los dos tienen `client`).
     *
     * @param string $comprobante 'la venta' | 'el presupuesto' (para el texto de "aparece cuando")
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_cliente($comprobante)
    {
        $con_cliente = 'Solo si '.$comprobante.' tiene cliente.';

        return [
            self::def('cliente_nombre', 'cliente', 'Nombre del cliente', 'Cliente', self::TIPO_TEXTO, 'Juan Pérez', $con_cliente),
            self::def('cliente_razon_social', 'cliente', 'Razón social', 'Razón social', self::TIPO_TEXTO, 'Pérez Hnos. S.A.', $con_cliente),
            self::def('cliente_documento', 'cliente', 'CUIT o DNI (el que tenga)', 'CUIT/DNI', self::TIPO_TEXTO, '20-12345678-9', 'Sale el CUIT; si el cliente no tiene, el DNI.'),
            self::def('cliente_cuit', 'cliente', 'CUIT', 'CUIT', self::TIPO_TEXTO, '20-12345678-9', $con_cliente),
            self::def('cliente_dni', 'cliente', 'DNI', 'DNI', self::TIPO_TEXTO, '12345678', $con_cliente),
            self::def('cliente_condicion_iva', 'cliente', 'Condición frente al IVA', 'Condición IVA', self::TIPO_TEXTO, 'Responsable inscripto', $con_cliente),
            self::def('cliente_telefono', 'cliente', 'Teléfono', 'Teléfono', self::TIPO_TEXTO, '11 5555-5555', $con_cliente),
            self::def('cliente_email', 'cliente', 'Email', 'Email', self::TIPO_TEXTO, 'juan@correo.com', $con_cliente),
            self::def('cliente_direccion', 'cliente', 'Dirección', 'Dirección', self::TIPO_TEXTO, 'Av. San Martín 1234', $con_cliente),
            self::def('cliente_localidad', 'cliente', 'Localidad', 'Localidad', self::TIPO_TEXTO, 'Rosario', $con_cliente),
            self::def('cliente_provincia', 'cliente', 'Provincia', 'Provincia', self::TIPO_TEXTO, 'Santa Fe', $con_cliente),
            self::def('cliente_numero', 'cliente', 'Número de cliente', 'N° de cliente', self::TIPO_TEXTO, '154', $con_cliente),
            self::def('cliente_lista_de_precios', 'cliente', 'Lista de precios del cliente', 'Lista del cliente', self::TIPO_TEXTO, 'Mayorista', $con_cliente),
            self::def('cliente_vendedor', 'cliente', 'Vendedor asignado al cliente', 'Vendedor del cliente', self::TIPO_TEXTO, 'Carla Gómez', $con_cliente),
            self::def('cliente_observaciones', 'cliente', 'Observaciones del cliente', 'Observaciones', self::TIPO_TEXTO_LARGO, 'Paga a 30 días', 'Es la nota de la ficha del cliente: fijate que no sea interna antes de imprimirla.'),
        ];
    }

    /**
     * Datos de la venta.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_venta()
    {
        return [
            self::def('venta_numero', 'venta', 'Número de venta', 'N° de venta', self::TIPO_TEXTO, '1520'),
            self::def('venta_fecha', 'venta', 'Fecha', 'Fecha', self::TIPO_TEXTO, '01/10/2026', 'Con "Imprimir con fecha actual" sale la fecha del día.'),
            self::def('venta_hora', 'venta', 'Hora', 'Hora', self::TIPO_TEXTO, '14:35'),
            self::def('venta_tipo', 'venta', 'Tipo de venta', 'Tipo de venta', self::TIPO_TEXTO, 'Mostrador', 'Solo si la venta tiene un tipo de venta.'),
            self::def('venta_estado', 'venta', 'Estado de la venta', 'Estado', self::TIPO_TEXTO, 'Entregada', 'Solo si la venta tiene un estado.'),
            self::def('venta_condicion', 'venta', 'Contado o cuenta corriente', 'Condición de venta', self::TIPO_TEXTO, 'Cuenta corriente'),
            self::def('venta_vendedor', 'venta', 'Vendedor de la venta', 'Vendedor', self::TIPO_TEXTO, 'Carla Gómez', 'Solo si la venta tiene vendedor.'),
            self::def('venta_empleado', 'venta', 'Empleado que la cargó', 'Empleado', self::TIPO_TEXTO, 'Martín López', 'Solo si la cargó un empleado.'),
            self::def('venta_atendido_por', 'venta', 'Atendido por (empleado o dueño)', 'Atendido por', self::TIPO_TEXTO, 'Martín López', 'El empleado que cargó la venta; si no hay, el dueño.'),
            self::def('venta_sucursal', 'venta', 'Sucursal', 'Sucursal', self::TIPO_TEXTO, 'Belgrano 450, Rosario', 'Solo si la venta tiene sucursal.'),
            self::def('venta_lista_de_precios', 'venta', 'Lista de precios', 'Lista de precios', self::TIPO_TEXTO, 'Minorista', 'Solo si la venta tiene lista de precios.'),
            self::def('venta_metodos_de_pago', 'venta', 'Métodos de pago', 'Métodos de pago', self::TIPO_LISTA, ['Efectivo: $10.000', 'Débito: $2.500'], 'Uno por renglón, con lo que se pagó con cada uno.'),
            self::def('venta_cajas', 'venta', 'Cajas donde entró la plata', 'Cajas', self::TIPO_LISTA, ['Caja mostrador'], 'Las cajas que se usaron en la venta, una por renglón.'),
            self::def('venta_moneda', 'venta', 'Moneda', 'Moneda', self::TIPO_TEXTO, 'Pesos'),
            self::def('venta_cotizacion', 'venta', 'Cotización del dólar', 'Cotización', self::TIPO_TEXTO, '$1.050', 'Solo en ventas en dólares.'),
            self::def('venta_cuotas', 'venta', 'Cantidad de cuotas', 'Cuotas', self::TIPO_TEXTO, '3', 'Solo si la venta se pagó en cuotas.'),
            self::def('venta_fecha_entrega', 'venta', 'Fecha de entrega', 'Fecha de entrega', self::TIPO_TEXTO, '05/10/2026', 'Solo si la venta tiene fecha de entrega.'),
            self::def('venta_orden_de_compra', 'venta', 'N° de orden de compra', 'Orden de compra', self::TIPO_TEXTO, 'OC-4471', 'Solo si la venta tiene número de orden de compra.'),
            self::def('venta_cantidad_de_unidades', 'venta', 'Cantidad de unidades vendidas', 'Unidades', self::TIPO_TEXTO, '12'),
            self::def('venta_observaciones', 'venta', 'Observaciones de la venta', 'Observaciones', self::TIPO_TEXTO_LARGO, 'Entregar por la tarde', 'Solo si la venta tiene observaciones.'),
        ];
    }

    /**
     * Los renglones de cuenta corriente que hoy salen fijos en el remito.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_cuenta_corriente()
    {
        $con_cuenta_corriente = 'Solo si la venta va a la cuenta corriente del cliente.';

        return [
            self::def('cc_saldo_anterior', 'cuenta_corriente', 'Saldo anterior', 'Saldo anterior', self::TIPO_TEXTO, self::EJEMPLO_SALDO_ANTERIOR, $con_cuenta_corriente),
            self::def('cc_compra_actual', 'cuenta_corriente', 'Compra actual (esta venta)', 'Compra actual', self::TIPO_TEXTO, '$12.500', $con_cuenta_corriente),
            self::def('cc_saldo', 'cuenta_corriente', 'Saldo actual', 'Saldo', self::TIPO_TEXTO, '$27.500', $con_cuenta_corriente),
        ];
    }

    /**
     * Totales de la venta. Los montos salen de la misma cuenta que el pie del remito de siempre.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_totales_de_venta()
    {
        $total = self::estilo(12, true, false, 'derecha');
        $renglon = self::estilo(9, false, false, 'derecha');

        return [
            self::def('tot_subtotal', 'totales', 'Sub total', 'Sub Total', self::TIPO_TEXTO, '$13.900', 'Solo si hay descuentos, recargos o ajustes.', $total, 'pie'),
            self::def('tot_descuentos', 'totales', 'Descuentos', '', self::TIPO_LISTA, ['Menos $1.390 (10% Efectivo) = $12.510'], 'Solo si la venta tiene descuentos. El formato lo elige "Detalle de descuentos y recargos".', $renglon, 'pie'),
            self::def('tot_recargos', 'totales', 'Recargos', '', self::TIPO_LISTA, ['Mas $625 (5% Tarjeta) = $13.135'], 'Solo si la venta tiene recargos.', $renglon, 'pie'),
            self::def('tot_canje_de_puntos', 'totales', 'Canje de puntos', '', self::TIPO_TEXTO, 'Menos $500 (canje de puntos)', 'Solo si el cliente pagó parte con puntos.', $renglon, 'pie'),
            self::def('tot_ajuste_del_total', 'totales', 'Ajuste del total', '', self::TIPO_TEXTO, 'Menos $12 (ajuste del total)', 'Solo si el total se forzó a mano.', $renglon, 'pie'),
            self::def('tot_total', 'totales', 'Total', 'Total', self::TIPO_TEXTO, '$12.500', null, $total, 'pie'),
            self::def('tot_puntos', 'totales', 'Puntos del cliente', '', self::TIPO_LISTA, ['Sumó 125 puntos', 'Acumula 1.340 puntos'], 'Solo con un programa de puntos activo.', self::estilo(9, false, true, 'derecha'), 'pie'),
            self::def('tot_comisiones', 'totales', 'Comisiones de vendedores', 'Comisiones', self::TIPO_LISTA, ['Carla Gómez 5%: $625'], 'Solo si la venta tiene comisiones.', null, 'pie'),
            self::def('tot_total_menos_comisiones', 'totales', 'Total menos comisiones', 'Total menos comisiones', self::TIPO_TEXTO, '$11.875', 'Solo si la venta tiene comisiones.', self::estilo(12, true), 'pie'),
            self::def('tot_costos', 'totales', 'Total de costos', 'Costos', self::TIPO_TEXTO, '$9.300', self::DATO_INTERNO, self::estilo(12, true), 'pie'),
            self::def('tot_ganancia', 'totales', 'Ganancia de la venta', 'Ganancia', self::TIPO_TEXTO, '$3.200', self::DATO_INTERNO, self::estilo(12, true), 'pie'),
        ];
    }

    /**
     * Datos del presupuesto.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_presupuesto()
    {
        return [
            self::def('presupuesto_numero', 'presupuesto', 'Número de presupuesto', 'N° de presupuesto', self::TIPO_TEXTO, '318'),
            self::def('presupuesto_fecha', 'presupuesto', 'Fecha', 'Fecha', self::TIPO_TEXTO, '01/10/2026', 'Con "Imprimir con fecha actual" sale la fecha del día.'),
            self::def('presupuesto_vendedor', 'presupuesto', 'Vendedor (el empleado que lo cargó)', 'Vendedor', self::TIPO_TEXTO, 'Martín López', 'Solo si lo cargó un empleado.'),
            self::def('presupuesto_sucursal', 'presupuesto', 'Sucursal', 'Sucursal', self::TIPO_TEXTO, 'Belgrano 450, Rosario', 'Solo si el presupuesto tiene sucursal.'),
            self::def('presupuesto_lista_de_precios', 'presupuesto', 'Lista de precios', 'Lista de precios', self::TIPO_TEXTO, 'Mayorista', 'Solo si el presupuesto tiene lista de precios.'),
            self::def('presupuesto_estado', 'presupuesto', 'Estado del presupuesto', 'Estado', self::TIPO_TEXTO, 'Pendiente', 'Solo si el presupuesto tiene estado.'),
            self::def('presupuesto_moneda', 'presupuesto', 'Moneda', 'Moneda', self::TIPO_TEXTO, 'Pesos'),
            self::def('presupuesto_cotizacion', 'presupuesto', 'Cotización del dólar', 'Cotización', self::TIPO_TEXTO, '$1.050', 'Solo en presupuestos en dólares.'),
            self::def('presupuesto_observaciones', 'presupuesto', 'Observaciones del presupuesto', 'Observaciones', self::TIPO_TEXTO_LARGO, 'Entrega en 48 horas hábiles', 'Solo si el presupuesto tiene observaciones.'),
        ];
    }

    /**
     * Totales del presupuesto: la misma cuenta que el pie del presupuesto de siempre
     * (BudgetPdfDocument::totals_rows()).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_totales_de_presupuesto()
    {
        $total = self::estilo(12, true, false, 'derecha');
        $renglon = self::estilo(9, false, false, 'derecha');

        return [
            self::def('tot_subtotal', 'totales', 'Sub total sin descuentos', 'Sub Total sin descuentos', self::TIPO_TEXTO, '$2.300', 'Solo si hay descuentos, recargos o un ajuste del total.', $total, 'pie'),
            self::def('tot_descuentos', 'totales', 'Descuentos', '', self::TIPO_LISTA, ['- 10% Descuento por volumen'], 'Solo si el presupuesto tiene descuentos.', $renglon, 'pie'),
            self::def('tot_recargos', 'totales', 'Recargos', '', self::TIPO_LISTA, ['+ 5% Recargo financiero'], 'Solo si el presupuesto tiene recargos.', $renglon, 'pie'),
            self::def('tot_ajuste_del_total', 'totales', 'Ajuste del total', '', self::TIPO_TEXTO, '- $12 Ajuste del total', 'Solo si el total se forzó a mano.', $renglon, 'pie'),
            self::def('tot_total', 'totales', 'Total', 'Total', self::TIPO_TEXTO, '$2.173,50', null, $total, 'pie'),
        ];
    }

    /**
     * Datos del comprador de la tienda (el pedido online no tiene `client`: tiene `buyer`).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_comprador()
    {
        return [
            self::def('comprador_nombre', 'comprador', 'Nombre del comprador', 'Cliente', self::TIPO_TEXTO, 'Laura Fernández'),
            self::def('comprador_telefono', 'comprador', 'Teléfono', 'Teléfono', self::TIPO_TEXTO, '11 4444-3333'),
            self::def('comprador_email', 'comprador', 'Email', 'Email', self::TIPO_TEXTO, 'laura@correo.com'),
            self::def('comprador_direccion', 'comprador', 'Dirección', 'Dirección', self::TIPO_TEXTO, 'Mitre 980', 'La del pedido; si el pedido no trae, la del comprador.'),
            self::def('comprador_localidad', 'comprador', 'Localidad', 'Localidad', self::TIPO_TEXTO, 'Rosario'),
            self::def('comprador_codigo_postal', 'comprador', 'Código postal', 'CP', self::TIPO_TEXTO, '2000'),
            self::def('comprador_cuit', 'comprador', 'CUIT (si está vinculado a un cliente)', 'CUIT', self::TIPO_TEXTO, '27-23456789-4', 'Solo si el comprador está vinculado a un cliente con CUIT.'),
        ];
    }

    /**
     * Datos del pedido online.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_pedido()
    {
        return [
            self::def('pedido_numero', 'pedido', 'Número de pedido', 'N° de pedido', self::TIPO_TEXTO, '87'),
            self::def('pedido_fecha', 'pedido', 'Fecha', 'Fecha', self::TIPO_TEXTO, '01/10/2026', 'Con "Imprimir con fecha actual" sale la fecha del día.'),
            self::def('pedido_estado', 'pedido', 'Estado del pedido', 'Estado', self::TIPO_TEXTO, 'Confirmado'),
            self::def('pedido_modalidad_de_entrega', 'pedido', 'Modalidad de entrega', 'Entrega', self::TIPO_TEXTO, 'Envío a domicilio'),
            self::def('pedido_direccion_de_envio', 'pedido', 'Dirección de envío', 'Dirección de envío', self::TIPO_TEXTO, 'Mitre 980, Rosario', 'Solo si el pedido se envía.'),
            self::def('pedido_envio_elegido', 'pedido', 'Envío elegido', 'Envío', self::TIPO_TEXTO, 'Andreani · Estándar', 'Solo si el pedido se envía.'),
            self::def('pedido_metodo_de_pago', 'pedido', 'Medio de pago', 'Medio de pago', self::TIPO_TEXTO, 'Mercado Pago'),
            self::def('pedido_cupon', 'pedido', 'Cupón', 'Cupón', self::TIPO_TEXTO, 'BIENVENIDA10', 'Solo si el pedido usó un cupón.'),
            self::def('pedido_fecha_entrega', 'pedido', 'Fecha de entrega', 'Fecha de entrega', self::TIPO_TEXTO, '03/10/2026', 'Solo si el pedido tiene fecha de entrega.'),
            self::def('pedido_vendedor', 'pedido', 'Vendedor', 'Vendedor', self::TIPO_TEXTO, 'Carla Gómez', 'Solo si el pedido tiene vendedor.'),
            self::def('pedido_deposito', 'pedido', 'Depósito', 'Depósito', self::TIPO_TEXTO, 'Belgrano 450', 'Solo si el pedido tiene depósito.'),
            self::def('pedido_notas', 'pedido', 'Notas del pedido', 'Notas', self::TIPO_TEXTO_LARGO, 'Tocar timbre 2B', 'Solo si el pedido tiene notas.'),
        ];
    }

    /**
     * Totales del pedido online. Mismo criterio conservador que OrderPdfDocument (decisión D6 de
     * la misión pdf-presupuestos-y-pedidos-personalizables): `orders.total` NO incluye envío, cupón
     * ni el ajuste del medio de pago, así que el "Total" solo se imprime cuando el pedido no tiene
     * ninguno de esos extras; si los tiene se imprime el "Subtotal" y los extras como informativos.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_de_totales_de_pedido()
    {
        $total = self::estilo(12, true, false, 'derecha');
        $renglon = self::estilo(9, false, false, 'derecha');

        return [
            self::def('tot_subtotal', 'totales', 'Subtotal (suma de los artículos)', 'Subtotal', self::TIPO_TEXTO, '$18.400', 'Solo si el pedido tiene envío, cupón o ajuste por medio de pago.', $total, 'pie'),
            self::def('tot_envio', 'totales', 'Envío', 'Envío', self::TIPO_TEXTO, '$2.500', 'Solo si el pedido tiene envío con costo.', $renglon, 'pie'),
            self::def('tot_cupon', 'totales', 'Cupón', 'Cupón', self::TIPO_TEXTO, 'BIENVENIDA10', 'Solo si el pedido usó un cupón.', $renglon, 'pie'),
            self::def('tot_ajuste_medio_de_pago', 'totales', 'Descuento o recargo del medio de pago', 'Medio de pago', self::TIPO_TEXTO, '-10%', 'Solo si el medio de pago tiene descuento o recargo.', $renglon, 'pie'),
            self::def('tot_total', 'totales', 'Total', 'Total', self::TIPO_TEXTO, '$18.400', 'Solo si el pedido no tiene envío, cupón ni ajuste por medio de pago (si los tiene, el total cobrado lo calcula la tienda).', $total, 'pie'),
        ];
    }

    /**
     * Lo que vale para los tres modelos.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function campos_otros()
    {
        $libre = self::def(self::KEY_TEXTO_LIBRE, 'otros', 'Texto libre', '', self::TIPO_TEXTO_LARGO, 'Gracias por su compra', 'Lo escribís vos. Se puede poner las veces que quieras.', null, 'pie');
        $libre['repetible'] = true;

        return [$libre];
    }

    // ── Constructores de definiciones ─────────────────────────────────────────────────────────

    /**
     * Arma la definición de un campo.
     *
     * @param string       $key
     * @param string       $categoria
     * @param string       $nombre
     * @param string       $etiqueta
     * @param string       $tipo
     * @param string|array $ejemplo
     * @param string|null  $aparece_cuando
     * @param array|null   $estilo        null = estilo() (9 pt, normal, a la izquierda)
     * @param string       $zona_sugerida 'superior' | 'pie'
     * @return array<string, mixed>
     */
    private static function def($key, $categoria, $nombre, $etiqueta, $tipo, $ejemplo, $aparece_cuando = null, $estilo = null, $zona_sugerida = 'superior')
    {
        return [
            'key' => $key,
            'categoria' => $categoria,
            'nombre' => $nombre,
            'etiqueta' => $etiqueta,
            'tipo' => $tipo,
            'ejemplo' => $ejemplo,
            'aparece_cuando' => $aparece_cuando,
            'repetible' => false,
            'zona_sugerida' => $zona_sugerida,
            'estilo' => is_null($estilo) ? self::estilo() : $estilo,
        ];
    }

    /**
     * Estilo de un campo.
     *
     * @param int    $tamano     puntos (DisenoDePaginaPdf::TAMANO_MIN..TAMANO_MAX)
     * @param bool   $negrita
     * @param bool   $cursiva
     * @param string $alineacion 'izquierda' | 'centro' | 'derecha'
     * @return array{tamano:int, negrita:bool, cursiva:bool, alineacion:string}
     */
    private static function estilo($tamano = 9, $negrita = false, $cursiva = false, $alineacion = 'izquierda')
    {
        return [
            'tamano' => $tamano,
            'negrita' => $negrita,
            'cursiva' => $cursiva,
            'alineacion' => $alineacion,
        ];
    }
}
