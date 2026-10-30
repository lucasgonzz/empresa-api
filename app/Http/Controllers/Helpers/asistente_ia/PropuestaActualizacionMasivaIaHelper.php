<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\PermisoStockPorSucursalHelper;
use App\Http\Controllers\Helpers\article\StockMinimoMaximoEnMasivaHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Iva;
use App\Services\AuditLog\AuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Actualización masiva de artículos propuesta por el asistente de IA (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026, plan §4.2, contrato §1.5): "los de tal proveedor,
 * de tal categoría y cuyo precio no se actualizó desde tal fecha → ponele el margen en 40 %".
 *
 * Se encola por MasiveUpdateHelper::encolar_actualizacion(), el MISMO camino que `PUT update/article`
 * del listado: mismo guard de filtros efectivos, mismo tope de 3000, misma `criteria_json` con
 * `resolved_models_id`, mismo historial de actualizaciones masivas y misma reversión. Lo que este
 * helper agrega es la traducción del lenguaje de la IA (filtros por FiltroDeArticulosIaHelper,
 * cambios por traducir_cambios()) al `update_form` que ya consume MasiveUpdateHelper::apply_form_change.
 *
 * 🔴 NUNCA SE AUTO-CONFIRMA, ni con el dueño en "resuelto". No está en
 * HerramientasDeCarga::AUTO_CONFIRMABLES y HerramientasDeCarga::ejecutar() no la pasa por
 * quizas_auto_confirmar(): una masiva reescribe precios, márgenes, stock o proveedores de cientos
 * de artículos de un saque, y aunque se pueda revertir, es exactamente el tipo de cosa que la
 * persona tiene que ver y confirmar (decisión de Lucas, plan §2). Hay un test que lo fija.
 *
 * 🔴 EN ejecutar() TODO SE RE-VERIFICA. Entre la tarjeta y el clic pueden pasar horas: los ids no
 * se guardan en la tarjeta, se resuelven de nuevo con el filtro al confirmar (es lo que hace
 * encolar_actualizacion con el filter_form), y el permiso se vuelve a mirar sobre quien confirma.
 */
class PropuestaActualizacionMasivaIaHelper
{
    /**
     * Permiso que espeja la pantalla: el dropdown de acciones del listado habilita "Actualizar"
     * con `can(model_name + '.update')` (src/common-vue/components/view/header/
     * opciones-filtrados-seleccion/OptionsDropdown.vue, `puede_actualizar`). El slug lo siembra
     * PermissionSeeder como "Actualizar articulos".
     */
    const PERMISO = 'article.update';

    /** Mismo tope que UpdateController / MasiveUpdateHelper::process_update. */
    const TOPE = 3000;

    const TITULO = 'Actualización masiva de artículos';

    const AVISO = 'Corre en segundo plano y recalcula el precio final de cada artículo. Queda en el historial de actualizaciones masivas y se puede revertir desde ahí.';

    /**
     * "Subí (o bajá) el precio X %" (misión asistente-masiva-precio-manual, 10/10/2026): el precio
     * que se cobra, no una columna. Va siempre como la operación de la masiva
     * PrecioFinalEnMasivaHelper, que decide por artículo al correr: en los de precio manual sube ese
     * precio, donde el margen no mueve el precio (listas, costo de lista + IVA) sube el costo, y en
     * el resto ajusta el margen. Ver cambio_precio_final().
     */
    const CAMPO_PRECIO_FINAL = 'precio_final';

    /** precio_final combinado con otro cambio en la misma tarjeta (ver analizar_cambios_de_precio()). */
    const TEXTO_PRECIO_EN_TARJETA_APARTE = 'Subir o bajar el precio va en una tarjeta aparte: primero ese cambio y después los demás.';

    /** El campo de la IA para el precio cargado a mano (CAMPOS_NUMERICOS). */
    const CAMPO_PRECIO_MANUAL = 'precio_manual';

    /**
     * Los campos numéricos que la IA puede cambiar → propiedad del artículo (la `key` del
     * update_form lleva el prefijo set_ / increment_ / decrement_). `formato` dice cómo se lee el
     * valor en la tarjeta.
     *
     * @var array<string, array<string, string>>
     */
    const CAMPOS_NUMERICOS = [
        'margen_de_ganancia'        => ['prop' => 'percentage_gain',        'etiqueta' => 'Margen de ganancia',        'formato' => 'porcentaje'],
        'precio_manual'             => ['prop' => 'price',                  'etiqueta' => 'Precio manual',             'formato' => 'plata'],
        'costo'                     => ['prop' => 'cost',                   'etiqueta' => 'Costo',                     'formato' => 'plata'],
        'stock'                     => ['prop' => 'stock',                  'etiqueta' => 'Stock',                     'formato' => 'numero'],
        'precio_promocional'        => ['prop' => 'precio_promocional',     'etiqueta' => 'Precio promocional',        'formato' => 'plata'],
        'margen_de_ganancia_blanco' => ['prop' => 'percentage_gain_blanco', 'etiqueta' => 'Margen de ganancia blanco', 'formato' => 'porcentaje'],
    ];

    /**
     * Las relaciones que se asignan por NOMBRE (misma resolución que el filtro) → columna `_id`.
     * En el update_form van con `type: search`, que es como las manda la pantalla.
     *
     * @var array<string, array<string, string>>
     */
    const CAMPOS_DE_RELACION = [
        'proveedor'     => ['prop' => 'provider_id',     'etiqueta' => 'Proveedor'],
        'categoria'     => ['prop' => 'category_id',     'etiqueta' => 'Categoría'],
        'sub_categoria' => ['prop' => 'sub_category_id', 'etiqueta' => 'Subcategoría'],
        'marca'         => ['prop' => 'brand_id',        'etiqueta' => 'Marca'],
    ];

    /**
     * Los selects (catálogos globales, sin dueño) → columna `_id`. `type: select` en el update_form.
     *
     * @var array<string, array<string, string>>
     */
    const CAMPOS_DE_SELECT = [
        'iva'              => ['prop' => 'iva_id',           'etiqueta' => 'IVA'],
        'unidad_de_medida' => ['prop' => 'unidad_medida_id', 'etiqueta' => 'Unidad de medida'],
    ];

    /**
     * Los checkbox que se activan o desactivan → columna. Son las propiedades con `use_to_update`
     * y `type: checkbox` de src/models/article.js.
     *
     * @var array<string, array<string, string>>
     */
    const CAMPOS_DE_CHECKBOX = [
        'en_tienda'                   => ['prop' => 'online',                         'etiqueta' => 'En tienda'],
        'destacado'                   => ['prop' => 'featured',                       'etiqueta' => 'Destacado'],
        'en_oferta'                   => ['prop' => 'in_offer',                       'etiqueta' => 'En oferta'],
        'precio_pausado'              => ['prop' => 'precio_pausado',                 'etiqueta' => 'Precio pausado'],
        'es_insumo'                   => ['prop' => 'es_insumo',                      'etiqueta' => 'Es insumo'],
        'aplica_margen_del_proveedor' => ['prop' => 'apply_provider_percentage_gain', 'etiqueta' => 'Aplica margen del proveedor'],
        'aplicar_iva'                 => ['prop' => 'aplicar_iva',                    'etiqueta' => 'Aplicar IVA'],
        'costo_en_dolares'            => ['prop' => 'cost_in_dollars',                'etiqueta' => 'Costo en dólares'],
        'disponible_tienda_nube'      => ['prop' => 'disponible_tienda_nube',         'etiqueta' => 'Disponible en Tienda Nube'],
    ];

    // -------------------------------------------------------------------------------------------
    // Stock mínimo y máximo (misión agente-mejoras-uso-real, E2 punto 1, 29/10/2026)
    // -------------------------------------------------------------------------------------------

    /**
     * El campo de la IA para el stock mínimo. Pablo (Ferre Total, CONV 14) pidió «a todos los clavos
     * punta París poneles stock mínimo 10» y el agente le dijo que la masiva no lo admitía y le
     * ofreció setear el STOCK en 10 (un ingreso de mercadería real). Ver cambio_de_minimo_o_maximo().
     */
    const CAMPO_STOCK_MINIMO = 'stock_minimo';

    /** El campo de la IA para el stock máximo (solo existe por sucursal, ver cambio_de_minimo_o_maximo()). */
    const CAMPO_STOCK_MAXIMO = 'stock_maximo';

    /**
     * El permiso de la pantalla para ver y editar el mínimo y el máximo
     * (`can('article.stock_min_max')` de EditAddressStock.vue; lo describe PermisosCatalogoHelper).
     */
    const PERMISO_MINIMO_Y_MAXIMO = 'article.stock_min_max';

    /**
     * Lo que dice la tarjeta del mínimo: las alertas de "Stock mínimo" salen del reporte nocturno de
     * inventario (InventoryPerformanceHelper), así que el mínimo nuevo no aparece en el acto.
     */
    const AVISO_DEL_MINIMO = 'El stock mínimo nuevo se ve en Alertas en el próximo reporte (04:00) o tocando Actualizar en Alertas.';

    /** Lo que dice la persona para "en todas las sucursales" (ya normalizado, ver normalizar_sucursal()). */
    const TODAS_LAS_SUCURSALES = ['todas', 'todos', 'todas las sucursales', 'todos los depositos', 'todas las sucursales y depositos', 'cada sucursal', 'en todas', 'en todas las sucursales'];

    /**
     * Herramienta proponer_actualizacion_masiva.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  filtros*, cambios*, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(self::mensaje_sin_permiso());
        }

        $filtros = EntradaDeCargaIa::valor($input, 'filtros');
        $cambios = EntradaDeCargaIa::valor($input, 'cambios');

        $filtros = is_array($filtros) ? array_values($filtros) : [];
        $cambios = is_array($cambios) ? array_values($cambios) : [];

        // Mismo guard que la pantalla: sin filtro no hay masiva (sería reescribir el catálogo entero).
        if (!count($filtros)) {

            return RespuestaDeCargaIa::faltan(['qué artículos alcanza: al menos un filtro (proveedor, categoría, fecha de actualización del precio, etc.)']);
        }

        if (!count($cambios)) {

            return RespuestaDeCargaIa::faltan(['qué cambio hay que aplicarles a esos artículos']);
        }

        $traducido = FiltroDeArticulosIaHelper::traducir($filtros, $contexto->owner_id);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        $cambios_traducidos = self::traducir_cambios($cambios, $contexto->owner_id, $contexto);

        if (RespuestaDeCargaIa::es_negativa($cambios_traducidos)) {

            return $cambios_traducidos;
        }

        /*
         * El mínimo o el máximo por sucursal se expanden en un renglón del update_form por sucursal,
         * así que ese update_form ya no está alineado uno a uno con los cambios. El único análisis que
         * reescribe el update_form por posición es el del precio (precio_manual / precio_final): con
         * uno de esos en la misma tarjeta, va aparte.
         */
        if (self::trae_minimo_o_maximo($cambios) && self::trae_cambio_de_precio($cambios)) {

            return RespuestaDeCargaIa::error('El stock mínimo o máximo va en una tarjeta aparte de un cambio de precio: primero uno y después el otro.');
        }

        $total = FiltroDeArticulosIaHelper::contar($contexto->owner_id, $traducido['filter_form'], $traducido['imagen']);

        if ($total === 0) {

            return RespuestaDeCargaIa::error('Ningún artículo cumple ese filtro. Revisá el filtro con la persona.');
        }

        if ($total >= self::TOPE) {

            return RespuestaDeCargaIa::error(
                'Son ' . $total . ' artículos y el tope de una actualización masiva es ' . self::TOPE . ': acotá el filtro.'
            );
        }

        /*
         * 🔴 Un cambio de precio que no le mueve el precio a nadie no se propone tal cual (misión
         * asistente-masiva-precio-manual, 10/10/2026). En demo, "subile 10 % el precio" salió como
         * "Precio manual sube 10 %" sobre artículos de costo + margen: tarjeta confirmada, masiva
         * completada, 0 cambios. Cuando hay un cambio de precio_manual o precio_final se miran los
         * alcanzados (una consulta más la del proveedor) para decir con qué se mueve cada uno; un
         * precio manual que no mueve a ninguno se convierte en precio final.
         */
        $analisis = self::analizar_cambios_de_precio($contexto, $traducido, $cambios, $cambios_traducidos);

        if (RespuestaDeCargaIa::es_negativa($analisis)) {

            return $analisis;
        }

        $cambios_traducidos['update_form'] = $analisis['update_form'];
        $cambios_traducidos['legibles'] = $analisis['legibles'];

        /*
         * La clave sale de los cambios con el precio manual ya convertido en precio final: así un
         * "precio_manual sube 10" convertido y un "precio_final sube 10" posterior son la MISMA carga
         * y la segunda reemplaza a la primera. Con la clave de los cambios crudos quedaban dos
         * tarjetas iguales vivas, y confirmar las dos subía 21 %.
         */
        $cambios = $analisis['cambios'];

        $renglones = [
            ['etiqueta' => 'Artículos alcanzados', 'valor' => (string) $total],
        ];

        foreach ($traducido['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        foreach ($cambios_traducidos['legibles'] as $legible) {

            $renglones[] = ['etiqueta' => 'Cambio', 'valor' => $legible];
        }

        // Los renglones del análisis de precio van después de los de "Cambio": explican cómo se aplica.
        $avisos = [];

        foreach ($analisis['renglones'] as $renglon) {

            $renglones[] = $renglon;
            $avisos[] = $renglon['valor'];
        }

        /*
         * Stock mínimo / máximo (misión agente-mejoras-uso-real, E2): los renglones de atención de
         * las sucursales donde algún alcanzado no tiene stock repartido (ahí no se le pone el mínimo,
         * ver StockMinimoMaximoEnMasivaHelper) y los avisos que trajo la traducción (sucursales que la
         * persona no puede tocar).
         */
        foreach (self::renglones_de_minimo_o_maximo($contexto, $traducido, $cambios_traducidos) as $renglon) {

            $renglones[] = $renglon;
            $avisos[] = $renglon['valor'];
        }

        $datos = [
            'filtros'          => $filtros,
            'filter_form'      => $traducido['filter_form'],
            'imagen'           => $traducido['imagen'],
            'update_form'      => $cambios_traducidos['update_form'],
            'cambios_legibles' => $cambios_traducidos['legibles'],
            'total_estimado'   => $total,
        ];

        /*
         * Misión asistente-altas-y-conversacion (16/10/2026, D3, demo3 S07/S08): "subile el costo 8 %
         * a todo lo de Ferretera del Sur" se confirmó sin que nadie dijera cómo quedaban los precios
         * de venta, y la ducha saltó de $ 22.415 a $ 29.292. Hasta tres ejemplos, calculados con la
         * MISMA cuenta que va a hacer la masiva (ver ejemplos_de_precio()). Van en los datos de la
         * tarjeta (y el resultado de ejecutar() los repite), en el aviso de la tarjeta y en la
         * respuesta al modelo. No cambian nada de lo que hace la masiva.
         */
        $ejemplos = self::ejemplos_de_precio($contexto, $traducido, $cambios, $cambios_traducidos['update_form']);

        $aviso_de_la_tarjeta = self::AVISO;

        if (count($ejemplos)) {

            $datos[self::CLAVE_EJEMPLOS] = $ejemplos;

            $aviso_de_la_tarjeta = self::AVISO . ' ' . self::texto_de_ejemplos($ejemplos) . '.';
        }

        // Con un mínimo o un máximo, dónde se ve: las alertas salen del reporte nocturno.
        if (self::trae_minimo_o_maximo($cambios)) {

            $aviso_de_la_tarjeta .= ' ' . self::AVISO_DEL_MINIMO;
            $avisos[] = self::AVISO_DEL_MINIMO;
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
            self::clave($filtros, $cambios),
            $datos,
            ['titulo' => self::TITULO, 'renglones' => $renglones, 'aviso' => $aviso_de_la_tarjeta],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $extra = [
            'articulos_alcanzados' => $total,
            'filtros_legibles'     => $traducido['legibles'],
            'cambios_legibles'     => $cambios_traducidos['legibles'],
        ];

        // Solo cuando los hay: la IA se los tiene que contar a la persona.
        if (count($avisos)) {

            $extra['avisos'] = $avisos;
        }

        if (count($ejemplos)) {

            $extra[self::CLAVE_EJEMPLOS] = self::lineas_de_ejemplos($ejemplos);
        }

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Actualización masiva de ' . $total . ' artículos: ' . implode('; ', $cambios_traducidos['legibles']),
            $extra
        );
    }

    /**
     * Mira con qué se mueve el precio de los artículos alcanzados cuando la masiva trae un cambio de
     * precio_manual o de precio_final, y devuelve los renglones que lo explican (`Cómo`, `Atención`),
     * el update_form y los legibles (que pueden cambiar: ver precio_manual), o el error si el cambio no
     * se puede proponer así.
     *
     *  - precio_final va SOLO en su tarjeta: combinado con otro cambio, error. Dos cambios sobre la
     *    misma columna rompen la reversión, y el análisis no puede predecir lo que hacen los otros.
     *  - precio_manual (salvo que la misma tarjeta cambie el margen, "aplica margen del proveedor",
     *    el proveedor o el costo, que cambian quién usa precio manual y no se puede predecir): si no
     *    le mueve el precio a ninguno, subir/bajar se convierte sola en precio_final con el mismo
     *    porcentaje y redondeo (con un renglón que lo dice, y el cambio pasa a `campo` precio_final
     *    para la clave), y setear es error con un texto para el dueño. Si a algunos no, renglón de
     *    atención.
     *  - precio_final: PrecioFinalEnMasivaHelper::clasificar() con el comercio. Ninguno con algo que
     *    mover → error. Renglón `Cómo` con las tres palancas y los de atención (ver
     *    analizar_precio_final()). El `round` y el "(redondeado…)" quedan solo si hay artículos de
     *    precio manual.
     *  - Con listas de precio, un cambio de margen suma un renglón: el margen no mueve las listas.
     *
     * Sin cambios de precio ni de margen no consulta nada.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $traducido  Lo que devolvió FiltroDeArticulosIaHelper::traducir().
     * @param  array  $cambios  Los cambios de la IA, ya validados por traducir_cambios().
     * @param  array  $cambios_traducidos  Lo que devolvió traducir_cambios() (alineado con $cambios).
     * @return array  ['renglones', 'update_form', 'legibles', 'cambios'] o la respuesta negativa.
     */
    protected static function analizar_cambios_de_precio(ContextoDeCargaIa $contexto, array $traducido, array $cambios, array $cambios_traducidos)
    {
        $update_form = $cambios_traducidos['update_form'];
        $legibles = $cambios_traducidos['legibles'];
        $renglones = [];

        $campos = [];

        foreach ($cambios as $i => $cambio) {

            $campos[$i] = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo'));
        }

        $hay_precio = in_array(self::CAMPO_PRECIO_FINAL, $campos, true) || in_array(self::CAMPO_PRECIO_MANUAL, $campos, true);
        $cambia_el_margen_del_articulo = in_array('margen_de_ganancia', $campos, true);

        if (!$hay_precio && !$cambia_el_margen_del_articulo) {

            return ['renglones' => [], 'update_form' => $update_form, 'legibles' => $legibles, 'cambios' => $cambios];
        }

        if (in_array(self::CAMPO_PRECIO_FINAL, $campos, true) && count($cambios) > 1) {

            return RespuestaDeCargaIa::error(self::TEXTO_PRECIO_EN_TARJETA_APARTE);
        }

        $comercio = PrecioFinalEnMasivaHelper::comercio($contexto->owner);

        /*
         * Con el margen, "aplica margen del proveedor", el proveedor o el costo cambiando en la misma
         * tarjeta no se puede predecir quién usa precio manual: los cuatro deciden si manda el margen.
         */
        $cambia_el_margen = count(array_intersect($campos, ['margen_de_ganancia', 'aplica_margen_del_proveedor', 'proveedor', 'costo'])) > 0;

        $articulos = null;

        foreach ($campos as $i => $campo) {

            $operacion = mb_strtolower(EntradaDeCargaIa::texto($cambios[$i], 'operacion'));
            $valor = EntradaDeCargaIa::valor($cambios[$i], 'valor');
            $redondear = !empty($cambios[$i]['redondear']);

            if ($campo === self::CAMPO_PRECIO_MANUAL && !$cambia_el_margen) {

                if (is_null($articulos)) {

                    $articulos = self::articulos_para_analizar($contexto, $traducido);
                }

                $resultado = self::analizar_precio_manual($articulos, $operacion, $comercio, count($cambios));

                if (RespuestaDeCargaIa::es_negativa($resultado)) {

                    return $resultado;
                }

                foreach ($resultado['renglones'] as $renglon) {

                    $renglones[] = $renglon;
                }

                if ($resultado['convertir']) {

                    $convertido = self::cambio_precio_final($operacion, $valor, $redondear);

                    if (RespuestaDeCargaIa::es_negativa($convertido)) {

                        return $convertido;
                    }

                    $update_form[$i] = $convertido['form'];
                    $legibles[$i] = $convertido['texto'];
                    $cambios[$i]['campo'] = self::CAMPO_PRECIO_FINAL;
                    $campo = self::CAMPO_PRECIO_FINAL;
                }
            }

            if ($campo === self::CAMPO_PRECIO_FINAL) {

                if (is_null($articulos)) {

                    $articulos = self::articulos_para_analizar($contexto, $traducido);
                }

                $resultado = self::analizar_precio_final($articulos, $operacion, $valor + 0, $redondear, $comercio, $contexto->owner);

                if (RespuestaDeCargaIa::es_negativa($resultado)) {

                    return $resultado;
                }

                // El redondeo solo toca a los de precio manual: sin ninguno, ni el `round` ni el texto.
                if ($redondear && !$resultado['hay_precio_manual']) {

                    unset($update_form[$i]['round']);
                    $legibles[$i] = self::texto_de_precio_final($operacion === 'subir_porcentaje', $valor + 0, false);
                }

                foreach ($resultado['renglones'] as $renglon) {

                    $renglones[] = $renglon;
                }
            }
        }

        if (!empty($comercio['listas_de_precio']) && $cambia_el_margen_del_articulo) {

            $renglones[] = ['etiqueta' => 'Atención', 'valor' => 'Con listas de precio, el margen del artículo no cambia el precio de las listas'];
        }

        return [
            'renglones'   => $renglones,
            'update_form' => $update_form,
            'legibles'    => $legibles,
            'cambios'     => $cambios,
        ];
    }

    /**
     * Los alcanzados con las columnas que miran la palanca y "por debajo de su costo" (con el
     * prefijo: el filtro puede joinear) y su proveedor, que trae el dólar con el que se cotiza el
     * costo. Una consulta más la del proveedor; nada de withAll().
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $traducido
     * @return \Illuminate\Support\Collection
     */
    protected static function articulos_para_analizar(ContextoDeCargaIa $contexto, array $traducido)
    {
        return FiltroDeArticulosIaHelper::query($contexto->owner_id, $traducido['filter_form'], $traducido['imagen'])
                                    ->with('provider')
                                    ->get([
                                        'articles.id',
                                        'articles.cost',
                                        'articles.price',
                                        'articles.percentage_gain',
                                        'articles.apply_provider_percentage_gain',
                                        'articles.provider_id',
                                        'articles.base_margen',
                                        'articles.costo_real',
                                        'articles.unidades_individuales',
                                        'articles.cost_in_dollars',
                                    ]);
    }

    /**
     * precio_manual: cuántos alcanzados mueve.
     *
     *  - Ninguno y setear → error con un texto para el dueño.
     *  - Ninguno y subir/bajar → se convierte en precio_final (`convertir`), con un renglón que lo
     *    dice. Si la tarjeta trae más cambios, error: el precio va en su tarjeta.
     *  - Algunos no → renglón de atención.
     *
     * @param  \Illuminate\Support\Collection  $articulos
     * @param  string  $operacion
     * @param  array  $comercio  PrecioFinalEnMasivaHelper::comercio().
     * @param  int  $cantidad_de_cambios
     * @return array  ['convertir' => bool, 'renglones' => [...]] o la respuesta negativa.
     */
    protected static function analizar_precio_manual($articulos, $operacion, array $comercio, $cantidad_de_cambios)
    {
        $total = count($articulos);
        $es_setear = $operacion === 'setear';

        $mueve = 0;

        foreach ($articulos as $articulo) {

            if (PrecioFinalEnMasivaHelper::el_precio_manual_lo_mueve($articulo, $es_setear, $comercio)) {

                $mueve++;
            }
        }

        if ($mueve === 0) {

            if ($es_setear) {

                if (!empty($comercio['listas_de_precio'])) {

                    return RespuestaDeCargaIa::error('Con listas de precio el precio manual no se usa: el precio de cada lista sale del costo.');
                }

                return RespuestaDeCargaIa::error($total === 1
                    ? 'El artículo alcanzado no usa precio manual: su precio se calcula a partir del costo, así que un precio fijado a mano no se usaría. Para fijarle un precio a mano hay que sacarle el margen en su ficha.'
                    : 'Ninguno de estos ' . $total . ' artículos usa precio manual: su precio se calcula a partir del costo, así que un precio fijado a mano no se usaría. Para fijarles un precio a mano hay que sacarles el margen en la ficha de cada artículo.');
            }

            if ($cantidad_de_cambios > 1) {

                return RespuestaDeCargaIa::error(self::TEXTO_PRECIO_EN_TARJETA_APARTE);
            }

            $se_mueve = $operacion === 'subir_porcentaje' ? 'se sube' : 'se baja';

            return [
                'convertir' => true,
                'renglones' => [
                    [
                        'etiqueta' => 'Atención',
                        'valor'    => $total === 1
                            ? 'El artículo alcanzado no usa precio manual: ' . $se_mueve . ' el precio final'
                            : 'Ninguno de estos ' . $total . ' artículos usa precio manual: ' . $se_mueve . ' el precio final',
                    ],
                ],
            ];
        }

        $no_mueve = $total - $mueve;

        if ($no_mueve <= 0) {

            return ['convertir' => false, 'renglones' => []];
        }

        return [
            'convertir' => false,
            'renglones' => [
                [
                    'etiqueta' => 'Atención',
                    'valor'    => $no_mueve === 1
                        ? '1 de ' . $total . ' artículos no usa precio manual: este cambio no le mueve el precio'
                        : $no_mueve . ' de ' . $total . ' artículos no usan precio manual: este cambio no les mueve el precio',
                ],
            ],
        ];
    }

    /**
     * precio_final: con qué palanca se mueve cada alcanzado (PrecioFinalEnMasivaHelper::clasificar())
     * y los renglones que lo cuentan:
     *
     *  - `Cómo`: cuántos ajustan el margen, cuántos suben el precio manual y cuántos suben el costo.
     *  - `Atención`: los que no tienen costo (con listas de precio el precio manual no se usa, así
     *    que ahí "sin precio" es "sin costo") o no tienen costo ni precio, los de precio manual en
     *    $0, los que quedarían por debajo de su costo (solo al bajar), que el precio en blanco de los
     *    que ajustan el margen no cambia (extensión de precios en blanco: sale del costo y del margen
     *    en blanco), que la suba del costo se reemplaza si después se actualiza el costo desde el
     *    proveedor, y (con listas de precio) los que tienen el precio de alguna lista fijado a mano,
     *    que esa lista no cambia (una consulta).
     *
     * @param  \Illuminate\Support\Collection  $articulos
     * @param  string  $operacion
     * @param  float  $porcentaje
     * @param  bool  $redondear
     * @param  array  $comercio  PrecioFinalEnMasivaHelper::comercio().
     * @param  \App\Models\User|null  $owner  El dueño (la cotización del costo lo necesita).
     * @return array  ['renglones' => [...], 'hay_precio_manual' => bool] o la respuesta negativa.
     */
    protected static function analizar_precio_final($articulos, $operacion, $porcentaje, $redondear, array $comercio, $owner = null)
    {
        $total = count($articulos);
        $sube = $operacion === 'subir_porcentaje';
        $con_listas = !empty($comercio['listas_de_precio']);

        $conteo = PrecioFinalEnMasivaHelper::clasificar($articulos, (float) $porcentaje, $sube, $comercio, $redondear, $owner);

        $margen = $conteo[PrecioFinalEnMasivaHelper::PALANCA_MARGEN];
        $manual = $conteo[PrecioFinalEnMasivaHelper::PALANCA_PRECIO_MANUAL];
        $costo = $conteo[PrecioFinalEnMasivaHelper::PALANCA_COSTO];
        $sin_precio = $conteo[PrecioFinalEnMasivaHelper::SIN_PRECIO];
        $en_cero = $conteo[PrecioFinalEnMasivaHelper::PRECIO_MANUAL_EN_CERO];

        if ($margen + $manual + $costo === 0) {

            return RespuestaDeCargaIa::error(self::texto_sin_precio_que_mover($total, $sin_precio, $en_cero, $sube, $con_listas));
        }

        $renglones = [
            ['etiqueta' => 'Cómo', 'valor' => self::texto_de_como($margen, $manual, $costo, $sube, self::valor_legible($porcentaje, 'porcentaje'))],
        ];

        if ($sin_precio > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => ($sin_precio === 1 ? '1 no tiene ' : $sin_precio . ' no tienen ')
                    . ($con_listas ? 'costo' : 'costo ni precio cargado')
                    . ($sin_precio === 1 ? ': no cambia' : ': no cambian'),
            ];
        }

        if ($en_cero > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $en_cero === 1
                    ? '1 tiene el precio manual en $0: no cambia'
                    : $en_cero . ' tienen el precio manual en $0: no cambian',
            ];
        }

        if ($conteo['debajo_del_costo'] > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $conteo['debajo_del_costo'] === 1
                    ? '1 quedaría por debajo de su costo'
                    : $conteo['debajo_del_costo'] . ' quedarían por debajo de su costo',
            ];
        }

        if ($margen > 0 && !empty($comercio['precios_en_blanco'])) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $margen === 1
                    ? 'El precio en blanco del artículo al que se le ajusta el margen no cambia'
                    : 'El precio en blanco de los ' . $margen . ' a los que se les ajusta el margen no cambia',
            ];
        }

        if ($costo > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => 'Si después se actualiza el costo desde el proveedor, ' . ($sube ? 'la suba' : 'la baja')
                    . ($costo === 1 ? ' de ese artículo' : ' de esos ' . $costo) . ' se reemplaza.',
            ];

            if (!empty($comercio['listas_de_precio'])) {

                $fijadas = (int) DB::table('article_price_type')
                                    ->whereIn('article_id', $conteo['ids_costo'])
                                    ->where('setear_precio_final', 1)
                                    ->distinct()
                                    ->count('article_id');

                if ($fijadas > 0) {

                    $renglones[] = [
                        'etiqueta' => 'Atención',
                        'valor'    => $fijadas === 1
                            ? '1 tiene el precio de alguna lista fijado a mano: esa lista no cambia'
                            : $fijadas . ' tienen el precio de alguna lista fijado a mano: esas listas no cambian',
                    ];
                }
            }
        }

        return [
            'renglones'         => $renglones,
            'hay_precio_manual' => $manual > 0,
        ];
    }

    /**
     * "En 3 artículos se ajusta el margen, en 2 sube el precio manual y en 1 sube el costo, para que
     * el precio final suba 10 %", sin las palancas en 0.
     *
     * @param  int  $margen
     * @param  int  $manual
     * @param  int  $costo
     * @param  bool  $sube
     * @param  string  $porcentaje  Ya legible ("10 %").
     * @return string
     */
    protected static function texto_de_como($margen, $manual, $costo, $sube, $porcentaje)
    {
        $partes = [];

        if ($margen > 0) {

            $partes[] = [$margen, 'se ajusta el margen'];
        }

        if ($manual > 0) {

            $partes[] = [$manual, ($sube ? 'sube' : 'baja') . ' el precio manual'];
        }

        if ($costo > 0) {

            $partes[] = [$costo, ($sube ? 'sube' : 'baja') . ' el costo'];
        }

        $frases = [];

        foreach ($partes as $k => $parte) {

            $frases[] = ($k === 0 ? 'En ' . self::cantidad_de_articulos($parte[0]) : 'en ' . $parte[0]) . ' ' . $parte[1];
        }

        $ultima = array_pop($frases);
        $texto = count($frases) ? implode(', ', $frases) . ' y ' . $ultima : $ultima;

        return $texto . (count($partes) > 1 ? ', ' : ' ') . 'para que el precio final ' . ($sube ? 'suba ' : 'baje ') . $porcentaje;
    }

    /**
     * El error de precio_final cuando ningún alcanzado tiene algo que mover.
     *
     * @param  int  $total
     * @param  int  $sin_precio
     * @param  int  $en_cero
     * @param  bool  $sube
     * @param  bool  $con_listas  Con listas de precio el precio manual no se usa: falta el costo.
     * @return string
     */
    protected static function texto_sin_precio_que_mover($total, $sin_precio, $en_cero, $sube, $con_listas = false)
    {
        $verbo = $sube ? 'subir' : 'bajar';
        $que_falta = $con_listas ? 'costo' : 'costo ni precio cargado';

        if ($total === 1) {

            return 'El artículo alcanzado no tiene un precio que ' . $verbo . ': '
                . ($en_cero > 0 ? 'tiene el precio manual en $0.' : 'no tiene ' . $que_falta . '.');
        }

        if ($en_cero === 0) {

            $motivo = 'no tienen ' . $que_falta . '.';

        } elseif ($sin_precio === 0) {

            $motivo = 'tienen el precio manual en $0.';

        } else {

            $motivo = ($sin_precio === 1 ? '1 no tiene' : $sin_precio . ' no tienen') . ' costo ni precio cargado y '
                . ($en_cero === 1 ? '1 tiene' : $en_cero . ' tienen') . ' el precio manual en $0.';
        }

        return 'Ninguno de estos ' . $total . ' artículos tiene un precio que ' . $verbo . ': ' . $motivo;
    }

    /**
     * "1 artículo", "3 artículos".
     *
     * @param  int  $cantidad
     * @return string
     */
    protected static function cantidad_de_articulos($cantidad)
    {
        return $cantidad . ($cantidad === 1 ? ' artículo' : ' artículos');
    }

    /**
     * Identidad de la carga para el reemplazo: los mismos filtros con los mismos cambios son la
     * misma masiva (una corrección reemplaza la tarjeta anterior); otro filtro u otro cambio, no.
     *
     * @param  array  $filtros
     * @param  array  $cambios
     * @return string
     */
    public static function clave(array $filtros, array $cambios)
    {
        return 'actualizacion_masiva:' . sha1((string) json_encode([$filtros, $cambios], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Encola la masiva. Corre en el request del clic (o con la persona puesta en Auth por el canal
     * de WhatsApp), adentro de la transacción de EjecutorAccionesIaHelper.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, self::mensaje_sin_permiso());
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $filter_form = isset($datos['filter_form']) && is_array($datos['filter_form']) ? $datos['filter_form'] : [];
        $update_form = isset($datos['update_form']) && is_array($datos['update_form']) ? $datos['update_form'] : [];
        $imagen = isset($datos['imagen']) ? $datos['imagen'] : null;

        if (!count($update_form)) {

            throw new AccionIaException(422, 'Esta tarjeta no tiene ningún cambio para aplicar. Pedímelo de nuevo.');
        }

        /*
         * El mínimo y el máximo se re-verifican sobre QUIEN CONFIRMA (misión agente-mejoras-uso-real,
         * E2): el permiso de la pantalla y, por sucursal, el mismo recorte que la edición de stock por
         * sucursal. Una tarjeta vieja no trae estas claves y no pasa por acá.
         */
        self::verificar_minimo_o_maximo_al_ejecutar($contexto, $update_form);

        $persona = $contexto->persona;

        $auth_user_id = !is_null($persona) ? (int) $persona->id : (int) $contexto->conversation->auth_user_id;

        /*
         * 🔴 LA CANTIDAD SE RE-CUENTA ANTES DE ENCOLAR Y SE COMPARA CON LA QUE VIO LA PERSONA. La
         * tarjeta decía "214 artículos" cuando se propuso; si entre la tarjeta y el clic entraron
         * artículos que cumplen el filtro (una importación, una carga), la persona estaría
         * confirmando otra cosa. Con más de un 10 % (y más de 3) de diferencia se corta y se pide
         * de nuevo: es un conteo, no una masiva, y evita el "confirmé 214 y tocó 400".
         */
        $estimado = isset($datos['total_estimado']) ? (int) $datos['total_estimado'] : 0;
        $fresco   = FiltroDeArticulosIaHelper::contar($contexto->owner_id, $filter_form, $imagen);

        if ($estimado > 0 && abs($fresco - $estimado) > max(3, (int) ceil($estimado * 0.10))) {

            throw new AccionIaException(422, 'Cambió la cantidad de artículos que cumplen el filtro (eran ' . $estimado . ', ahora son ' . $fresco . '): pedímelo de nuevo para ver la cantidad actual.');
        }

        /*
         * Con al menos un filtro de columna, se encola por el MISMO camino que la pantalla
         * (SearchController::search + ColumnFiltersHelper), y el filtro de imagen —que no es una
         * columna— se aplica sobre lo resuelto. Con SOLO el filtro de imagen no hay columna que
         * acote el search, y "traer el catálogo entero con withAll() para filtrarlo en PHP" en el
         * request del clic es un OOM en un catálogo grande (chequeo 2 de la misión): en ese caso los
         * ids se resuelven en SQL (whereDoesntHave/whereHas) y se encolan como selección, con el
         * criterio legible en el historial.
         */
        if (!count($filter_form) && !is_null($imagen)) {

            $ids = FiltroDeArticulosIaHelper::ids($contexto->owner_id, [], $imagen);

            $resultado = MasiveUpdateHelper::encolar_actualizacion(
                'article',
                false,
                [],
                $update_form,
                $ids,
                $contexto->owner_id,
                $auth_user_id,
                null,
                [['key' => 'imagen', 'operator' => $imagen, 'value' => true, 'type' => 'imagen']]
            );
        } else {

            $resultado = MasiveUpdateHelper::encolar_actualizacion(
                'article',
                true,
                $filter_form,
                $update_form,
                [],
                $contexto->owner_id,
                $auth_user_id,
                self::filtro_de_imagen($imagen)
            );
        }

        if ((int) $resultado['status'] !== 200) {

            $mensaje = isset($resultado['body']['message']) ? (string) $resultado['body']['message'] : 'No se pudo encolar la actualización masiva.';

            throw new AccionIaException(422, $mensaje);
        }

        $encolados = isset($resultado['body']['queued_count']) ? (int) $resultado['body']['queued_count'] : 0;

        $resultado_de_la_tarjeta = [
            'texto' => 'Actualización masiva encolada: ' . $encolados . ' artículos. Te va a aparecer en el sistema cuando termine.',
            'ruta'  => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
        ];

        /*
         * Los ejemplos de precio que vio la persona al proponer, repetidos (misión
         * asistente-altas-y-conversacion, D3): la masiva corre en segundo plano y el modelo tiene que
         * poder decir cómo quedan los precios sin esperar a que termine. `texto` no cambia.
         */
        if (isset($datos[self::CLAVE_EJEMPLOS]) && is_array($datos[self::CLAVE_EJEMPLOS]) && count($datos[self::CLAVE_EJEMPLOS])) {

            $resultado_de_la_tarjeta[self::CLAVE_EJEMPLOS] = self::lineas_de_ejemplos($datos[self::CLAVE_EJEMPLOS]);
        }

        return $resultado_de_la_tarjeta;
    }

    // -------------------------------------------------------------------------------------------
    // Ejemplos de precio de venta (misión asistente-altas-y-conversacion, D3)
    // -------------------------------------------------------------------------------------------

    /** La clave de los ejemplos en los datos de la tarjeta, en la respuesta al modelo y en el resultado. */
    const CLAVE_EJEMPLOS = 'ejemplos_de_precio';

    /** Cuántos ejemplos como máximo. */
    const MAX_EJEMPLOS = 3;

    /**
     * Los cambios con los que el precio de venta de después se puede estimar con confianza: los que
     * solo mueven columnas que lee el cálculo del precio. Un cambio de proveedor (descuentos que se
     * materializan, embeddings), de categoría, de stock (movimientos) o de un checkbox de la tienda
     * no se simula: con cualquiera de ellos en la tarjeta no hay ejemplos.
     */
    const CAMBIOS_QUE_SE_PUEDEN_ESTIMAR = [
        'costo', 'margen_de_ganancia', 'precio_manual', self::CAMPO_PRECIO_FINAL,
        'aplica_margen_del_proveedor', 'costo_en_dolares', 'aplicar_iva', 'iva',
    ];

    /**
     * Hasta MAX_EJEMPLOS artículos alcanzados con su precio de venta actual (`final_price`) y el
     * estimado después de la masiva.
     *
     * 🔴 El "después" sale de la MISMA cuenta de la masiva, no de una fórmula aparte: dentro de una
     * transacción que siempre se revierte, a cada ejemplo se le aplica cada ítem del update_form con
     * MasiveUpdateHelper::apply_form_change() (lo que hace process_update()) y se le recalcula el
     * precio con ArticleHelper::setFinalPrice() (lo que hace la tanda), y se relee `final_price`. Así
     * el ejemplo dice lo que la masiva va a dejar — también cuando el precio guardado no coincide
     * con la fórmula (demo3: la ducha a $ 22.415 con la fórmula dando otro número), que es
     * justamente lo que el dueño tiene que ver antes de confirmar. Nada de lo simulado queda: el
     * cálculo solo escribe en la base (artículo y pivotes de listas) y el rollback lo deshace.
     *
     * Solo con cambios de CAMBIOS_QUE_SE_PUEDEN_ESTIMAR; los artículos son los primeros por id con
     * precio de venta. Protegido: si algo falla, sin ejemplos (la tarjeta se arma igual).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $traducido  FiltroDeArticulosIaHelper::traducir().
     * @param  array  $cambios  Los cambios ya analizados (con el precio manual convertido si correspondía).
     * @param  array  $update_form
     * @return array<int, array{article_id: int, nombre: string, antes: float, despues: float}>
     */
    protected static function ejemplos_de_precio(ContextoDeCargaIa $contexto, array $traducido, array $cambios, array $update_form)
    {
        if (!count($update_form) || count($update_form) !== count($cambios)) {

            return [];
        }

        foreach ($cambios as $cambio) {

            $campo = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo'));

            if (!in_array($campo, self::CAMBIOS_QUE_SE_PUEDEN_ESTIMAR, true)) {

                return [];
            }
        }

        $ejemplos = [];

        try {

            $ids = FiltroDeArticulosIaHelper::query($contexto->owner_id, $traducido['filter_form'], $traducido['imagen'])
                                        ->where('articles.final_price', '>', 0)
                                        ->orderBy('articles.id')
                                        ->limit(self::MAX_EJEMPLOS)
                                        ->pluck('articles.id')
                                        ->all();

            if (!count($ids)) {

                return [];
            }

            $owner = $contexto->owner;

            /*
             * El contador de filas de auditoría del lote vive en memoria (AuditContext::marco()), no
             * en la base: el rollback deshace las filas pero no el conteo, y una propuesta con
             * ejemplos le comería tope (`max_filas_por_lote`) al resto del request. Se guarda acá y
             * se devuelve después del rollback (chequeo independiente, 16/10/2026).
             */
            $auditoria = self::conteo_de_auditoria();

            DB::beginTransaction();

            try {

                foreach ($ids as $id) {

                    $articulo = Article::find((int) $id);

                    if (is_null($articulo)) {

                        continue;
                    }

                    $antes = (float) $articulo->final_price;
                    $nombre = (string) $articulo->name;

                    foreach ($update_form as $form) {

                        MasiveUpdateHelper::apply_form_change($articulo, $form, $owner);
                    }

                    ArticleHelper::setFinalPrice($articulo, (int) $contexto->owner_id, $owner);

                    $despues = DB::table('articles')->where('id', (int) $id)->value('final_price');

                    if (is_null($despues) || (float) $despues <= 0) {

                        continue;
                    }

                    $ejemplos[] = [
                        'article_id' => (int) $id,
                        'nombre'     => $nombre,
                        'antes'      => round($antes, 2),
                        'despues'    => round((float) $despues, 2),
                    ];
                }

            } finally {

                DB::rollBack();

                self::restaurar_conteo_de_auditoria($auditoria);
            }

        } catch (\Throwable $e) {

            Log::warning('PropuestaActualizacionMasivaIaHelper: no se pudieron calcular los ejemplos de precio', [
                'owner_id' => $contexto->owner_id,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }

        return $ejemplos;
    }

    /**
     * El conteo en memoria del lote de auditoría (filas, omitidas, id de la fila truncada), o null
     * si no se puede leer.
     *
     * Público desde la misión agente-mejoras-uso-real (E2): la estimación del precio de una lista en
     * la edición de márgenes (MargenesPorListaIaHelper::precios_estimados()) simula con la misma
     * transacción que se revierte y tiene que devolver el mismo conteo.
     *
     * @return array|null
     */
    public static function conteo_de_auditoria()
    {
        try {

            $marco = AuditContext::marco();

            return [
                'marco'       => $marco,
                'filas'       => isset($marco->filas) ? $marco->filas : 0,
                'omitidas'    => isset($marco->omitidas) ? $marco->omitidas : 0,
                'id_truncada' => isset($marco->id_truncada) ? $marco->id_truncada : null,
            ];

        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * Devuelve el conteo de auditoría a como estaba antes de los ejemplos (lo escrito adentro se
     * revirtió con el rollback, incluida una fila `truncated` que se hubiera creado).
     *
     * @param  array|null  $conteo
     * @return void
     */
    public static function restaurar_conteo_de_auditoria($conteo)
    {
        if (!is_array($conteo) || !is_object($conteo['marco'])) {

            return;
        }

        $conteo['marco']->filas = $conteo['filas'];
        $conteo['marco']->omitidas = $conteo['omitidas'];
        $conteo['marco']->id_truncada = $conteo['id_truncada'];
    }

    /**
     * "Ducha de mano Gloa: $ 22.415,01 → $ 29.291,94", uno por ejemplo.
     *
     * @param  array  $ejemplos
     * @return array<int, string>
     */
    protected static function lineas_de_ejemplos(array $ejemplos)
    {
        $lineas = [];

        foreach ($ejemplos as $ejemplo) {

            if (!is_array($ejemplo) || !isset($ejemplo['nombre'], $ejemplo['antes'], $ejemplo['despues'])) {

                continue;
            }

            $lineas[] = $ejemplo['nombre'] . ': ' . FormatoIaHelper::monto($ejemplo['antes']) . ' → ' . FormatoIaHelper::monto($ejemplo['despues']);
        }

        return $lineas;
    }

    /**
     * "Ejemplos de precio de venta: Ducha…: $ 22.415,01 → $ 29.291,94; Llave…: …".
     *
     * @param  array  $ejemplos
     * @return string
     */
    protected static function texto_de_ejemplos(array $ejemplos)
    {
        return 'Ejemplos de precio de venta: ' . implode('; ', self::lineas_de_ejemplos($ejemplos));
    }

    /**
     * El `$filtro_extra` de encolar_actualizacion() para el filtro de imagen, que no es una columna
     * y por eso no entra en el filter_form: se aplica sobre la colección que resolvió el search.
     * `images` ya viene cargada por withAll(), así que no hay una consulta por artículo.
     *
     * @param  string|null  $imagen  null | 'en_blanco' | 'no_en_blanco'
     * @return callable|null
     */
    protected static function filtro_de_imagen($imagen)
    {
        if ($imagen !== FiltroDeArticulosIaHelper::IMAGEN_EN_BLANCO && $imagen !== FiltroDeArticulosIaHelper::IMAGEN_NO_EN_BLANCO) {

            return null;
        }

        return function ($models) use ($imagen) {

            $filtrados = [];

            foreach ($models as $model) {

                if (!$model) {

                    continue;
                }

                $tiene_imagen = count($model->images) > 0;

                if (($imagen === FiltroDeArticulosIaHelper::IMAGEN_EN_BLANCO && !$tiene_imagen)
                    || ($imagen === FiltroDeArticulosIaHelper::IMAGEN_NO_EN_BLANCO && $tiene_imagen)) {

                    $filtrados[] = $model;
                }
            }

            return [
                'models'      => $filtrados,
                'used_filter' => ['key' => 'imagen', 'operator' => $imagen, 'value' => true, 'type' => 'imagen'],
            ];
        };
    }

    /**
     * Traduce los cambios de la IA (`[{campo, operacion, valor, redondear}]`) al update_form de
     * MasiveUpdateHelper::apply_form_change, que es el mismo que arma el modal de la pantalla.
     *
     * Misión agente-mejoras-uso-real (E2, 29/10/2026): `stock_minimo` y `stock_maximo` (ver
     * cambio_de_minimo_o_maximo()). Con 2 o más sucursales un cambio de esos puede dar VARIOS
     * renglones del update_form (uno por sucursal) y uno solo en los legibles; por eso el resultado
     * trae además `minimos_y_maximos` (las sucursales de cada uno) y `avisos_de_minimo`. Sin esos dos
     * campos, el resultado es exactamente el de antes.
     *
     * @param  array  $cambios
     * @param  int  $owner_id
     * @param  ContextoDeCargaIa|null  $contexto  Para el mínimo y el máximo (permisos y sucursales). Opcional.
     * @return array  ['update_form' => [...], 'legibles' => [...]] o la respuesta negativa.
     */
    public static function traducir_cambios(array $cambios, $owner_id, $contexto = null)
    {
        $update_form = [];
        $legibles = [];
        $minimos_y_maximos = [];
        $avisos_de_minimo = [];

        foreach ($cambios as $cambio) {

            if (!is_array($cambio)) {

                return RespuestaDeCargaIa::error('Cada cambio tiene que venir como {campo, operacion, valor}.');
            }

            $campo = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo'));
            $operacion = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'operacion'));
            $valor = EntradaDeCargaIa::valor($cambio, 'valor');

            /*
             * El mínimo y el máximo pueden ser un renglón del update_form o varios (uno por
             * sucursal): van por su lado y siguen con el próximo cambio.
             */
            if ($campo === self::CAMPO_STOCK_MINIMO || $campo === self::CAMPO_STOCK_MAXIMO) {

                $traducido = self::cambio_de_minimo_o_maximo($campo, $operacion, $valor, EntradaDeCargaIa::texto($cambio, 'sucursal'), $owner_id, $contexto);

                if (RespuestaDeCargaIa::es_negativa($traducido)) {

                    return $traducido;
                }

                foreach ($traducido['forms'] as $form) {

                    $update_form[] = $form;
                }

                $legibles[] = $traducido['texto'];
                $minimos_y_maximos[] = $traducido['sucursales'];

                foreach ($traducido['avisos'] as $aviso) {

                    $avisos_de_minimo[] = $aviso;
                }

                continue;
            }

            if ($campo === self::CAMPO_PRECIO_FINAL) {

                $traducido = self::cambio_precio_final($operacion, $valor, !empty($cambio['redondear']));

            } elseif (isset(self::CAMPOS_NUMERICOS[$campo])) {

                $traducido = self::cambio_numerico(self::CAMPOS_NUMERICOS[$campo], $operacion, $valor, !empty($cambio['redondear']));

            } elseif (isset(self::CAMPOS_DE_RELACION[$campo])) {

                $traducido = self::cambio_de_relacion($campo, $operacion, $valor, $owner_id);

            } elseif (isset(self::CAMPOS_DE_SELECT[$campo])) {

                $traducido = self::cambio_de_select($campo, $operacion, $valor);

            } elseif (isset(self::CAMPOS_DE_CHECKBOX[$campo])) {

                $traducido = self::cambio_de_checkbox(self::CAMPOS_DE_CHECKBOX[$campo], $operacion);

            } else {

                return RespuestaDeCargaIa::error(
                    'No conozco el campo "' . $campo . '" para actualizar artículos. Los campos válidos son: ' . implode(', ', self::campos_validos()) . '.'
                );
            }

            if (RespuestaDeCargaIa::es_negativa($traducido)) {

                return $traducido;
            }

            $update_form[] = $traducido['form'];
            $legibles[] = $traducido['texto'];
        }

        if (!count($update_form)) {

            return RespuestaDeCargaIa::faltan(['qué cambio hay que aplicarles a esos artículos']);
        }

        $resultado = [
            'update_form' => $update_form,
            'legibles'    => $legibles,
        ];

        // Solo con un mínimo o un máximo: sin ellos, la forma de siempre.
        if (count($minimos_y_maximos)) {

            $resultado['minimos_y_maximos'] = $minimos_y_maximos;
            $resultado['avisos_de_minimo'] = $avisos_de_minimo;
        }

        return $resultado;
    }

    /**
     * El stock mínimo o el máximo de los alcanzados (misión agente-mejoras-uso-real, E2 punto 1).
     *
     * Dónde vive depende de cuántas sucursales tiene el negocio (FichaArticuloIaHelper::
     * tiene_depositos(), el mismo criterio de la interfaz: "las sucursales se muestran desde la
     * segunda"):
     *
     *  - 0 o 1 sucursal: en `articles.stock_min`, por la rama genérica `set_` de
     *    MasiveUpdateHelper::apply_form_change() (queda en el historial y se revierte como cualquier
     *    columna). El máximo solo si `articles` tiene una columna `stock_max` (hoy no la tiene: el
     *    máximo existe solo por sucursal) — si no, se dice.
     *  - 2 o más: en el pivote de cada sucursal (`stock_min_sucursal_{id}` / `stock_max_sucursal_{id}`,
     *    ver StockMinimoMaximoEnMasivaHelper). La sucursal va por nombre o "todas"; sin sucursal se
     *    pregunta con la lista. Cada sucursal tiene que poder tocarla la persona
     *    (PermisoStockPorSucursalHelper, el recorte de la pantalla): con "todas", las que no puede se
     *    dejan afuera y se avisa; nombrada una que no puede, error.
     *
     * Siempre exige `article.stock_min_max` (el permiso de la pantalla para el mínimo y el máximo) y
     * la operación es solo setear, con un entero mayor o igual a 0 (las dos columnas son INT).
     *
     * 🔴 NUNCA es el `stock`: el stock se cambia con un movimiento de mercadería real. Por eso es un
     * campo aparte y la descripción de la herramienta le prohíbe al modelo ofrecerlo como reemplazo.
     *
     * @param  string  $campo  stock_minimo | stock_maximo
     * @param  string  $operacion
     * @param  mixed  $valor
     * @param  string  $sucursal  El nombre de la sucursal, "todas" o vacío.
     * @param  int  $owner_id
     * @param  ContextoDeCargaIa|null  $contexto
     * @return array  ['forms' => [...], 'texto' => string, 'sucursales' => [{address_id, nombre}],
     *                 'avisos' => [...]] o la respuesta negativa.
     */
    protected static function cambio_de_minimo_o_maximo($campo, $operacion, $valor, $sucursal, $owner_id, $contexto = null)
    {
        $es_minimo = $campo === self::CAMPO_STOCK_MINIMO;
        $etiqueta = $es_minimo ? 'Stock mínimo' : 'Stock máximo';

        $persona = !is_null($contexto) ? $contexto->persona : null;

        if (!PermisosIaHelper::puede($persona, self::PERMISO_MINIMO_Y_MAXIMO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('el stock mínimo y máximo de los artículos'));
        }

        if ($operacion !== 'setear') {

            return RespuestaDeCargaIa::error('Para ' . $etiqueta . ' la única operación es setear, con el número en valor.');
        }

        if (!is_numeric($valor) || (float) $valor < 0) {

            return RespuestaDeCargaIa::error('El valor de ' . $etiqueta . ' tiene que ser un número mayor o igual a 0.');
        }

        if ((float) $valor != (float) (int) $valor) {

            return RespuestaDeCargaIa::error($etiqueta . ' se guarda como número entero: decime ' . (int) floor((float) $valor) . ' o ' . (int) ceil((float) $valor) . '.');
        }

        $numero = (int) $valor;

        // 0 o 1 sucursal: la columna del artículo.
        if (!FichaArticuloIaHelper::tiene_depositos((int) $owner_id)) {

            $columna = $es_minimo ? 'stock_min' : 'stock_max';

            if (!$es_minimo && !Schema::hasColumn('articles', 'stock_max')) {

                return RespuestaDeCargaIa::error('Con una sola sucursal el sistema guarda solo el stock mínimo del artículo: el stock máximo existe por sucursal, y este negocio no reparte el stock entre sucursales. El mínimo sí se puede poner.');
            }

            return [
                'forms'      => [['type' => 'number', 'key' => 'set_' . $columna, 'value' => $numero]],
                'texto'      => $etiqueta . ' → ' . $numero,
                'sucursales' => [],
                'avisos'     => [],
            ];
        }

        // 2 o más sucursales: por sucursal, con su permiso.
        $elegidas = self::sucursales_para_minimo_o_maximo((int) $owner_id, $sucursal, $persona);

        if (RespuestaDeCargaIa::es_negativa($elegidas)) {

            return $elegidas;
        }

        $forms = [];
        $sucursales = [];
        $nombres = [];

        foreach ($elegidas['sucursales'] as $deposito) {

            $forms[] = [
                'type'  => StockMinimoMaximoEnMasivaHelper::TIPO,
                'key'   => $es_minimo
                    ? StockMinimoMaximoEnMasivaHelper::clave_minimo($deposito->id)
                    : StockMinimoMaximoEnMasivaHelper::clave_maximo($deposito->id),
                'value' => $numero,
                // Para el historial de actualizaciones masivas, que muestra el label si viene.
                'label' => $etiqueta . ' en ' . PropuestaStockIaHelper::nombre_de_deposito($deposito),
            ];

            $sucursales[] = ['address_id' => (int) $deposito->id, 'nombre' => PropuestaStockIaHelper::nombre_de_deposito($deposito)];
            $nombres[] = PropuestaStockIaHelper::nombre_de_deposito($deposito);
        }

        return [
            'forms'      => $forms,
            'texto'      => $etiqueta . ' → ' . $numero . ' en ' . self::enumerar($nombres),
            'sucursales' => $sucursales,
            'avisos'     => $elegidas['avisos'],
        ];
    }

    /**
     * Las sucursales a las que va un mínimo o un máximo: la nombrada, o "todas" las que la persona
     * puede tocar.
     *
     * @param  int  $owner_id
     * @param  string  $texto  Nombre, "todas" o vacío.
     * @param  \App\Models\User|null  $persona
     * @return array  ['sucursales' => Address[], 'avisos' => string[]] o la respuesta negativa.
     */
    protected static function sucursales_para_minimo_o_maximo($owner_id, $texto, $persona)
    {
        $depositos = Address::sucursales_de($owner_id)->get();

        $opciones = [];

        foreach ($depositos as $deposito) {

            $opciones[] = ['nombre' => PropuestaStockIaHelper::nombre_de_deposito($deposito)];
        }

        $opciones[] = ['nombre' => 'todas'];

        $buscado = self::normalizar_sucursal($texto);

        // Sin sucursal se pregunta: con dos o más, el mínimo de un depósito no es el de otro.
        if ($buscado === '') {

            return RespuestaDeCargaIa::faltan(
                ['en qué sucursal va (o "todas"): este negocio reparte el stock entre sucursales y el mínimo es de cada una'],
                ['sucursales' => $opciones]
            );
        }

        if (in_array($buscado, self::TODAS_LAS_SUCURSALES, true)) {

            $permitidas = [];
            $afuera = [];

            foreach ($depositos as $deposito) {

                if (PermisoStockPorSucursalHelper::puede_editar($persona, $deposito->id)) {

                    $permitidas[] = $deposito;

                } else {

                    $afuera[] = PropuestaStockIaHelper::nombre_de_deposito($deposito);
                }
            }

            if (!count($permitidas)) {

                return RespuestaDeCargaIa::error('Tu usuario no puede tocar el stock de ninguna sucursal.');
            }

            $avisos = [];

            if (count($afuera)) {

                $avisos[] = 'Tu usuario solo puede tocar el stock de su sucursal: en ' . self::enumerar($afuera) . ' no se cambia';
            }

            return ['sucursales' => $permitidas, 'avisos' => $avisos];
        }

        $exactas = [];
        $parecidas = [];

        foreach ($depositos as $deposito) {

            $nombre = self::normalizar_sucursal(PropuestaStockIaHelper::nombre_de_deposito($deposito));

            if ($nombre === $buscado) {

                $exactas[] = $deposito;

            } elseif ($nombre !== '' && strpos($nombre, $buscado) !== false) {

                $parecidas[] = $deposito;
            }
        }

        $encontradas = count($exactas) ? $exactas : $parecidas;

        if (!count($encontradas)) {

            return RespuestaDeCargaIa::faltan(
                ['en qué sucursal va: no hay ninguna que se llame "' . trim((string) $texto) . '"'],
                ['sucursales' => $opciones]
            );
        }

        if (count($encontradas) > 1) {

            $candidatas = [];

            foreach ($encontradas as $deposito) {

                $candidatas[] = ['nombre' => PropuestaStockIaHelper::nombre_de_deposito($deposito)];
            }

            return RespuestaDeCargaIa::faltan(['en cuál de estas sucursales va'], ['sucursales' => $candidatas]);
        }

        if (!PermisoStockPorSucursalHelper::puede_editar($persona, $encontradas[0]->id)) {

            return RespuestaDeCargaIa::error('Tu usuario solo puede tocar el stock de la sucursal que tiene asignada, y ' . PropuestaStockIaHelper::nombre_de_deposito($encontradas[0]) . ' no es esa.');
        }

        return ['sucursales' => [$encontradas[0]], 'avisos' => []];
    }

    /**
     * Los renglones de la tarjeta que explican el mínimo o el máximo por sucursal: los avisos de la
     * traducción (sucursales afuera por permiso) y, por cada sucursal, cuántos alcanzados no tienen
     * stock repartido ahí (a esos no se les pone, ver StockMinimoMaximoEnMasivaHelper). Una consulta
     * por sucursal. Sin mínimo ni máximo por sucursal, ninguno.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $traducido  FiltroDeArticulosIaHelper::traducir().
     * @param  array  $cambios_traducidos  traducir_cambios().
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones_de_minimo_o_maximo(ContextoDeCargaIa $contexto, array $traducido, array $cambios_traducidos)
    {
        if (!isset($cambios_traducidos['minimos_y_maximos'])) {

            return [];
        }

        $renglones = [];

        foreach ($cambios_traducidos['avisos_de_minimo'] as $aviso) {

            $renglones[] = ['etiqueta' => 'Atención', 'valor' => $aviso];
        }

        // Cada sucursal una vez, aunque el mínimo y el máximo vayan a la misma.
        $vistas = [];

        foreach ($cambios_traducidos['minimos_y_maximos'] as $sucursales) {

            foreach ($sucursales as $sucursal) {

                if (isset($vistas[$sucursal['address_id']])) {

                    continue;
                }

                $vistas[$sucursal['address_id']] = true;

                $sin_fila = StockMinimoMaximoEnMasivaHelper::contar_sin_fila(
                    FiltroDeArticulosIaHelper::query($contexto->owner_id, $traducido['filter_form'], $traducido['imagen']),
                    $sucursal['address_id']
                );

                if ($sin_fila > 0) {

                    $renglones[] = [
                        'etiqueta' => 'Atención',
                        'valor'    => ($sin_fila === 1 ? '1 de los alcanzados no tiene' : $sin_fila . ' de los alcanzados no tienen')
                            . ' stock repartido en ' . $sucursal['nombre'] . ': ahí no se ' . ($sin_fila === 1 ? 'le' : 'les') . ' pone',
                    ];
                }
            }
        }

        return $renglones;
    }

    /**
     * Re-verifica, al confirmar, el permiso del mínimo y el máximo sobre quien confirma: el de la
     * pantalla (`article.stock_min_max`) para cualquiera de los dos y, para los de una sucursal, el
     * recorte por sucursal. Sin ninguna de esas claves en el update_form (toda tarjeta vieja), no
     * hace nada.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $update_form
     * @return void
     *
     * @throws AccionIaException  422 si la persona ya no puede.
     */
    protected static function verificar_minimo_o_maximo_al_ejecutar(ContextoDeCargaIa $contexto, array $update_form)
    {
        foreach ($update_form as $form) {

            $clave = is_array($form) && isset($form['key']) ? (string) $form['key'] : '';

            $es_de_sucursal = StockMinimoMaximoEnMasivaHelper::es_clave_de_masiva($clave);

            if (!$es_de_sucursal && $clave !== 'set_stock_min' && $clave !== 'set_stock_max') {

                continue;
            }

            if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO_MINIMO_Y_MAXIMO)) {

                throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('el stock mínimo y máximo de los artículos'));
            }

            if ($es_de_sucursal) {

                $partes = StockMinimoMaximoEnMasivaHelper::partes_de_la_clave($clave);

                if (!PermisoStockPorSucursalHelper::puede_editar($contexto->persona, $partes['address_id'])) {

                    throw new AccionIaException(422, 'Tu usuario no puede tocar el stock de una de las sucursales de esta tarjeta. Pedímelo de nuevo.');
                }
            }
        }
    }

    /**
     * true si alguno de los cambios es el stock mínimo o el máximo.
     *
     * @param  array  $cambios
     * @return bool
     */
    protected static function trae_minimo_o_maximo(array $cambios)
    {
        foreach ($cambios as $cambio) {

            $campo = is_array($cambio) ? mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo')) : '';

            if ($campo === self::CAMPO_STOCK_MINIMO || $campo === self::CAMPO_STOCK_MAXIMO) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si alguno de los cambios es de precio (precio_final o precio_manual): los que
     * analizar_cambios_de_precio() reescribe por posición en el update_form.
     *
     * @param  array  $cambios
     * @return bool
     */
    protected static function trae_cambio_de_precio(array $cambios)
    {
        foreach ($cambios as $cambio) {

            $campo = is_array($cambio) ? mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo')) : '';

            if ($campo === self::CAMPO_PRECIO_FINAL || $campo === self::CAMPO_PRECIO_MANUAL) {

                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas, sin tildes y con los espacios colapsados, para comparar nombres de sucursal.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar_sucursal($texto)
    {
        return trim((string) preg_replace('/\s+/u', ' ', ConsultasSistemaIaHelper::normalize_text((string) $texto)));
    }

    /**
     * "A", "A y B", "A, B y C".
     *
     * @param  array<int, string>  $partes
     * @return string
     */
    protected static function enumerar(array $partes)
    {
        $partes = array_values($partes);

        if (count($partes) <= 1) {

            return count($partes) ? (string) $partes[0] : '';
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    /**
     * setear → set_<prop>; subir_porcentaje → increment_<prop>; bajar_porcentaje → decrement_<prop>.
     *
     * @param  array  $definicion
     * @param  string  $operacion
     * @param  mixed  $valor
     * @param  bool  $redondear
     * @return array|array  ['form', 'texto'] o negativa.
     */
    protected static function cambio_numerico(array $definicion, $operacion, $valor, $redondear)
    {
        $etiqueta = $definicion['etiqueta'];

        if (!in_array($operacion, ['setear', 'subir_porcentaje', 'bajar_porcentaje'], true)) {

            return RespuestaDeCargaIa::error('Para ' . $etiqueta . ' la operación tiene que ser setear, subir_porcentaje o bajar_porcentaje.');
        }

        if (!is_numeric($valor)) {

            return RespuestaDeCargaIa::error('El valor de ' . $etiqueta . ' tiene que ser un número.');
        }

        $numero = $valor + 0;

        if ($operacion === 'setear') {

            if ($numero < 0) {

                return RespuestaDeCargaIa::error($etiqueta . ' no puede quedar en un número negativo.');
            }

            return [
                'form' => ['type' => 'number', 'key' => 'set_' . $definicion['prop'], 'value' => $numero],
                'texto' => $etiqueta . ' → ' . self::valor_legible($numero, $definicion['formato']),
            ];
        }

        if ($numero <= 0) {

            return RespuestaDeCargaIa::error('El porcentaje para subir o bajar ' . $etiqueta . ' tiene que ser mayor a 0.');
        }

        $prefijo = $operacion === 'subir_porcentaje' ? 'increment_' : 'decrement_';

        $form = ['type' => 'number', 'key' => $prefijo . $definicion['prop'], 'value' => $numero];

        if ($redondear) {

            $form['round'] = true;
        }

        return [
            'form'  => $form,
            'texto' => $etiqueta . ($operacion === 'subir_porcentaje' ? ' sube ' : ' baja ') . self::valor_legible($numero, 'porcentaje') . ($redondear ? ' (redondeado)' : ''),
        ];
    }

    /**
     * precio_final: subir_porcentaje o bajar_porcentaje del precio que se cobra.
     *
     * Siempre la operación de la masiva PrecioFinalEnMasivaHelper (`type` precio_final, con su
     * `label` para el historial): la palanca de cada artículo (precio manual, margen o costo) la
     * decide la operación al correr, con el comercio, también con listas de precio. `redondear` solo
     * redondea los de precio manual; si no hay ninguno, analizar_cambios_de_precio() saca el `round`
     * y el "(redondeado…)" del texto.
     *
     * No se puede setear: el precio final se calcula. Para fijar un precio a mano está precio_manual.
     *
     * @param  string  $operacion
     * @param  mixed  $valor
     * @param  bool  $redondear
     * @return array  ['form', 'texto'] o negativa.
     */
    protected static function cambio_precio_final($operacion, $valor, $redondear)
    {
        if ($operacion === 'setear') {

            return RespuestaDeCargaIa::error('El precio final se calcula, no se fija: se le puede subir o bajar un porcentaje. Para fijar un precio a mano está el precio manual, que solo cuenta en los artículos sin margen.');
        }

        if (!in_array($operacion, ['subir_porcentaje', 'bajar_porcentaje'], true)) {

            return RespuestaDeCargaIa::error('El precio final solo se puede subir o bajar un porcentaje.');
        }

        if (!is_numeric($valor)) {

            return RespuestaDeCargaIa::error('El porcentaje para subir o bajar el precio tiene que ser un número.');
        }

        $numero = $valor + 0;
        $sube = $operacion === 'subir_porcentaje';

        if ($numero <= 0) {

            return RespuestaDeCargaIa::error('El porcentaje para subir o bajar el precio tiene que ser mayor a 0.');
        }

        if (!$sube && $numero >= 100) {

            return RespuestaDeCargaIa::error('Para bajar el precio el porcentaje tiene que ser menor a 100.');
        }

        $form = [
            'type'  => PrecioFinalEnMasivaHelper::TIPO,
            'key'   => $sube ? PrecioFinalEnMasivaHelper::SUBIR : PrecioFinalEnMasivaHelper::BAJAR,
            'value' => $numero,
            'label' => $sube ? PrecioFinalEnMasivaHelper::LABEL_SUBIR : PrecioFinalEnMasivaHelper::LABEL_BAJAR,
        ];

        if ($redondear) {

            $form['round'] = true;
        }

        return [
            'form'  => $form,
            'texto' => self::texto_de_precio_final($sube, $numero, $redondear),
        ];
    }

    /**
     * "Precio final sube 10 %", con "(redondeado en los de precio manual)" si corresponde.
     *
     * @param  bool  $sube
     * @param  int|float  $numero
     * @param  bool  $con_redondeo
     * @return string
     */
    protected static function texto_de_precio_final($sube, $numero, $con_redondeo)
    {
        return 'Precio final' . ($sube ? ' sube ' : ' baja ') . self::valor_legible($numero, 'porcentaje')
            . ($con_redondeo ? ' (redondeado en los de precio manual)' : '');
    }

    /**
     * asignar un proveedor, una categoría, una subcategoría o una marca, por nombre.
     *
     * @param  string  $campo
     * @param  string  $operacion
     * @param  mixed  $valor
     * @param  int  $owner_id
     * @return array
     */
    protected static function cambio_de_relacion($campo, $operacion, $valor, $owner_id)
    {
        $definicion = self::CAMPOS_DE_RELACION[$campo];

        if ($operacion !== 'asignar') {

            return RespuestaDeCargaIa::error('Para ' . $definicion['etiqueta'] . ' la única operación es asignar (con el nombre).');
        }

        $modelo = FiltroDeArticulosIaHelper::resolver_relacion($owner_id, $campo, is_scalar($valor) ? (string) $valor : '');

        if (RespuestaDeCargaIa::es_negativa($modelo)) {

            return $modelo;
        }

        return [
            'form'  => ['type' => 'search', 'key' => $definicion['prop'], 'value' => (int) $modelo->id],
            'texto' => $definicion['etiqueta'] . ' → ' . $modelo->name,
        ];
    }

    /**
     * asignar un IVA (por su porcentaje o su nombre: "21", "21 %", "exento") o una unidad de medida
     * (por nombre). Son catálogos globales, sin dueño.
     *
     * @param  string  $campo
     * @param  string  $operacion
     * @param  mixed  $valor
     * @return array
     */
    protected static function cambio_de_select($campo, $operacion, $valor)
    {
        $definicion = self::CAMPOS_DE_SELECT[$campo];

        if ($operacion !== 'asignar') {

            return RespuestaDeCargaIa::error('Para ' . $definicion['etiqueta'] . ' la única operación es asignar.');
        }

        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['qué ' . mb_strtolower($definicion['etiqueta']) . ' hay que asignar']);
        }

        if ($campo === 'iva') {

            $iva = self::resolver_iva($texto);

            if (RespuestaDeCargaIa::es_negativa($iva)) {

                return $iva;
            }

            return [
                'form'  => ['type' => 'select', 'key' => $definicion['prop'], 'value' => (int) $iva->id],
                'texto' => 'IVA → ' . self::nombre_de_iva($iva),
            ];
        }

        $unidad = self::resolver_unidad_de_medida($texto);

        if (RespuestaDeCargaIa::es_negativa($unidad)) {

            return $unidad;
        }

        return [
            'form'  => ['type' => 'select', 'key' => $definicion['prop'], 'value' => (int) $unidad->id],
            'texto' => 'Unidad de medida → ' . $unidad->name,
        ];
    }

    /**
     * activar → 1, desactivar → 0.
     *
     * @param  array  $definicion
     * @param  string  $operacion
     * @return array
     */
    protected static function cambio_de_checkbox(array $definicion, $operacion)
    {
        if (!in_array($operacion, ['activar', 'desactivar'], true)) {

            return RespuestaDeCargaIa::error('Para ' . $definicion['etiqueta'] . ' la operación tiene que ser activar o desactivar.');
        }

        $activar = $operacion === 'activar';

        return [
            'form'  => ['type' => 'checkbox', 'key' => $definicion['prop'], 'value' => $activar ? 1 : 0],
            'texto' => $definicion['etiqueta'] . ' → ' . ($activar ? 'activado' : 'desactivado'),
        ];
    }

    /**
     * El IVA cuyo `percentage` coincide con lo que dijo la persona. La columna es texto y mezcla
     * números con "Exento" y "No Gravado", así que se compara de las dos formas: numérica cuando
     * los dos lados son números ("21" = "21.0"), y por texto sin el signo % en los demás.
     *
     * @param  string  $texto
     * @return \App\Models\Iva|array
     */
    protected static function resolver_iva($texto)
    {
        $buscado = self::normalizar_iva($texto);

        $todos = Iva::orderBy('id')->get();

        foreach ($todos as $iva) {

            $percentage = self::normalizar_iva((string) $iva->percentage);

            if ($percentage === $buscado) {

                return $iva;
            }

            if (is_numeric($percentage) && is_numeric($buscado) && (float) $percentage === (float) $buscado) {

                return $iva;
            }
        }

        $nombres = [];

        foreach ($todos as $iva) {

            $nombres[] = self::nombre_de_iva($iva);
        }

        return RespuestaDeCargaIa::error('No existe un IVA "' . $texto . '". Los que hay son: ' . implode(', ', $nombres) . '.');
    }

    /**
     * "21 %" → "21", "Exento" → "exento", "10,5" → "10.5".
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar_iva($texto)
    {
        return mb_strtolower(trim(str_replace([' ', '%', ','], ['', '', '.'], (string) $texto)));
    }

    /**
     * @param  \App\Models\Iva  $iva
     * @return string
     */
    protected static function nombre_de_iva(Iva $iva)
    {
        $percentage = trim((string) $iva->percentage);

        return is_numeric($percentage) ? $percentage . ' %' : $percentage;
    }

    /**
     * La unidad de medida por nombre.
     *
     * Misión agente-mejoras-uso-real (E2 punto 2, 29/10/2026): hasta acá era un `LIKE '%texto%'`
     * contra el nombre, y «kilos» no encajaba nunca con «Kilo» (lo buscado era más largo que lo
     * cargado); sin coincidencias devolvía un error sin opciones y el agente le terminaba preguntando
     * al dueño cómo estaba cargada (CONV 14 de Pablo). Ahora resuelve UnidadDeMedidaIaHelper, que
     * comparte con la carga genérica: alias (kg, kilos → Kilo), sin plural, en las dos direcciones, y
     * sin ninguna coincidencia devuelve `faltan` con TODAS las unidades en `unidades_de_medida`.
     *
     * @param  string  $texto
     * @return \App\Models\UnidadMedida|array
     */
    protected static function resolver_unidad_de_medida($texto)
    {
        return UnidadDeMedidaIaHelper::resolver($texto);
    }

    /**
     * "40 %", "$ 1.500", "10".
     *
     * @param  int|float  $numero
     * @param  string  $formato  porcentaje | plata | numero
     * @return string
     */
    protected static function valor_legible($numero, $formato)
    {
        if ($formato === 'porcentaje') {

            return self::numero_legible($numero) . ' %';
        }

        if ($formato === 'plata') {

            return FormatoIaHelper::monto($numero);
        }

        return self::numero_legible($numero);
    }

    /**
     * Sin decimales cuando son ,00; con coma decimal cuando no.
     *
     * @param  int|float  $numero
     * @return string
     */
    protected static function numero_legible($numero)
    {
        $redondeado = round((float) $numero, 2);

        if ((float) (int) $redondeado === $redondeado) {

            return (string) (int) $redondeado;
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($redondeado, 2, '.', ''), '0'), '.'));
    }

    /**
     * @return array<int, string>
     */
    protected static function campos_validos()
    {
        return array_merge(
            [self::CAMPO_PRECIO_FINAL],
            array_keys(self::CAMPOS_NUMERICOS),
            array_keys(self::CAMPOS_DE_RELACION),
            array_keys(self::CAMPOS_DE_SELECT),
            array_keys(self::CAMPOS_DE_CHECKBOX),
            // Al final: misión agente-mejoras-uso-real (E2).
            [self::CAMPO_STOCK_MINIMO, self::CAMPO_STOCK_MAXIMO]
        );
    }

    /**
     * @return string
     */
    protected static function mensaje_sin_permiso()
    {
        return PermisosIaHelper::mensaje_sin_permiso('actualizaciones masivas de artículos');
    }
}
