<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Iva;
use App\Models\UnidadMedida;

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
     * que se cobra, no una columna. Con precio único va como la operación de la masiva
     * PrecioFinalEnMasivaHelper (en los de costo + margen ajusta el margen, en los de precio manual
     * sube ese precio); con listas de precio, como subir o bajar el costo (cada lista sale del costo).
     * Ver cambio_precio_final().
     */
    const CAMPO_PRECIO_FINAL = 'precio_final';

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

        $usa_listas = self::usa_listas_de_precio($contexto);

        $cambios_traducidos = self::traducir_cambios($cambios, $contexto->owner_id, $usa_listas);

        if (RespuestaDeCargaIa::es_negativa($cambios_traducidos)) {

            return $cambios_traducidos;
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
         * 🔴 Un cambio de precio que no le mueve el precio a nadie no se propone (misión
         * asistente-masiva-precio-manual, 10/10/2026). En demo, "subile 10 % el precio" salió como
         * "Precio manual sube 10 %" sobre artículos de costo + margen: tarjeta confirmada, masiva
         * completada, 0 cambios. Solo cuando hay un cambio de precio_manual o precio_final se miran
         * los alcanzados (una consulta más la del proveedor) para decir cuántos mueve y cómo.
         */
        $analisis = self::analizar_cambios_de_precio($contexto, $traducido, $cambios, $usa_listas);

        if (RespuestaDeCargaIa::es_negativa($analisis)) {

            return $analisis;
        }

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

        $datos = [
            'filtros'          => $filtros,
            'filter_form'      => $traducido['filter_form'],
            'imagen'           => $traducido['imagen'],
            'update_form'      => $cambios_traducidos['update_form'],
            'cambios_legibles' => $cambios_traducidos['legibles'],
            'total_estimado'   => $total,
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
            self::clave($filtros, $cambios),
            $datos,
            ['titulo' => self::TITULO, 'renglones' => $renglones, 'aviso' => self::AVISO],
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

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Actualización masiva de ' . $total . ' artículos: ' . implode('; ', $cambios_traducidos['legibles']),
            $extra
        );
    }

    /**
     * Si el comercio trabaja con listas de precio (preferencia del dueño). Con listas el precio
     * manual no se usa nunca y cada lista sale del costo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return bool
     */
    protected static function usa_listas_de_precio(ContextoDeCargaIa $contexto)
    {
        // Sin dueño resuelto no se le pasa null a UserHelper: caería en Auth, que en el job no existe.
        return !is_null($contexto->owner) && UserHelper::uses_listas_de_precio($contexto->owner);
    }

    /**
     * Mira cómo calculan el precio los artículos alcanzados cuando la masiva trae un cambio de
     * precio_manual o de precio_final, y devuelve los renglones que lo explican (`Cómo`, `Atención`)
     * o el error si el cambio no le mueve el precio a ninguno.
     *
     *  - precio_manual: cuántos mueve (PrecioFinalEnMasivaHelper::el_precio_manual_lo_mueve(); con
     *    listas, ninguno). Ninguno → error sin tarjeta, con la sugerencia de precio_final para
     *    subir/bajar. Algunos no → renglón de atención.
     *  - precio_final con precio único: PrecioFinalEnMasivaHelper::clasificar(). Ninguno con precio →
     *    error. Renglón `Cómo` con cuántos ajustan el margen y cuántos suben el precio manual, y de
     *    atención los sin costo ni precio y (al bajar) los que quedarían con margen negativo.
     *  - precio_final con listas: sube el costo; los sin costo no cambian. Ninguno con costo → error.
     *
     * Sin ningún cambio de precio no consulta nada.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $traducido  Lo que devolvió FiltroDeArticulosIaHelper::traducir().
     * @param  array  $cambios  Los cambios de la IA, ya validados por traducir_cambios().
     * @param  bool  $usa_listas
     * @return array  ['renglones' => [...]] o la respuesta negativa.
     */
    protected static function analizar_cambios_de_precio(ContextoDeCargaIa $contexto, array $traducido, array $cambios, $usa_listas)
    {
        $de_precio = [];

        foreach ($cambios as $cambio) {

            $campo = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo'));

            if ($campo === self::CAMPO_PRECIO_MANUAL || $campo === self::CAMPO_PRECIO_FINAL) {

                $de_precio[] = [
                    'campo'     => $campo,
                    'operacion' => mb_strtolower(EntradaDeCargaIa::texto($cambio, 'operacion')),
                    'valor'     => EntradaDeCargaIa::valor($cambio, 'valor') + 0,
                ];
            }
        }

        if (!count($de_precio)) {

            return ['renglones' => []];
        }

        /*
         * Una consulta con las columnas que mira la regla (con el prefijo: el filtro puede joinear) y
         * la del proveedor, que hace falta para saber si manda su margen. Nada de withAll().
         */
        $articulos = FiltroDeArticulosIaHelper::query($contexto->owner_id, $traducido['filter_form'], $traducido['imagen'])
                                    ->with('provider')
                                    ->get([
                                        'articles.id',
                                        'articles.cost',
                                        'articles.price',
                                        'articles.percentage_gain',
                                        'articles.apply_provider_percentage_gain',
                                        'articles.provider_id',
                                    ]);

        $renglones = [];

        foreach ($de_precio as $cambio) {

            if ($cambio['campo'] === self::CAMPO_PRECIO_MANUAL) {

                $resultado = self::analizar_precio_manual($articulos, $cambio, $usa_listas);

            } elseif ($usa_listas) {

                $resultado = self::analizar_precio_final_con_listas($articulos, $cambio);

            } else {

                $resultado = self::analizar_precio_final($articulos, $cambio);
            }

            if (RespuestaDeCargaIa::es_negativa($resultado)) {

                return $resultado;
            }

            foreach ($resultado as $renglon) {

                $renglones[] = $renglon;
            }
        }

        return ['renglones' => $renglones];
    }

    /**
     * precio_manual: si el cambio no le mueve el precio a ninguno de los alcanzados, error (sin
     * tarjeta); si a algunos no, renglón de atención.
     *
     * @param  \Illuminate\Support\Collection  $articulos
     * @param  array  $cambio  {campo, operacion, valor}
     * @param  bool  $usa_listas
     * @return array  Los renglones o la respuesta negativa.
     */
    protected static function analizar_precio_manual($articulos, array $cambio, $usa_listas)
    {
        $total = count($articulos);
        $es_setear = $cambio['operacion'] === 'setear';

        // Con listas de precio el precio manual no se usa nunca: no le mueve el precio a ninguno.
        $mueve = 0;

        if (!$usa_listas) {

            foreach ($articulos as $articulo) {

                if (PrecioFinalEnMasivaHelper::el_precio_manual_lo_mueve($articulo, $es_setear)) {

                    $mueve++;
                }
            }
        }

        if ($mueve === 0) {

            $motivo = $total === 1
                ? 'El artículo alcanzado no usa precio manual: su precio final sale del costo + margen (con listas de precio: de las listas), así que cambiar el precio manual no le mueve el precio.'
                : 'Ninguno de los ' . $total . ' artículos usa precio manual: su precio final sale del costo + margen (con listas de precio: de las listas), así que cambiar el precio manual no les mueve el precio.';

            if ($es_setear) {

                $motivo .= ' Para fijarles un precio a mano hay que sacarles el margen desde la ficha de cada artículo; contáselo a la persona.';

                return RespuestaDeCargaIa::error($motivo);
            }

            $motivo .= ' Si la persona quiere subir o bajar el precio, usá el campo precio_final con el mismo porcentaje.';

            return RespuestaDeCargaIa::error($motivo, [
                'sugerencia' => [
                    'campo'     => self::CAMPO_PRECIO_FINAL,
                    'operacion' => $cambio['operacion'],
                    'valor'     => $cambio['valor'],
                ],
            ]);
        }

        $no_mueve = $total - $mueve;

        if ($no_mueve <= 0) {

            return [];
        }

        return [
            [
                'etiqueta' => 'Atención',
                'valor'    => $no_mueve === 1
                    ? '1 de ' . $total . ' artículos no usa precio manual: este cambio no le mueve el precio'
                    : $no_mueve . ' de ' . $total . ' artículos no usan precio manual: este cambio no les mueve el precio',
            ],
        ];
    }

    /**
     * precio_final con precio único: cuántos ajustan el margen, cuántos suben el precio manual y
     * cuántos no cambian.
     *
     * @param  \Illuminate\Support\Collection  $articulos
     * @param  array  $cambio  {campo, operacion, valor}
     * @return array  Los renglones o la respuesta negativa.
     */
    protected static function analizar_precio_final($articulos, array $cambio)
    {
        $total = count($articulos);
        $sube = $cambio['operacion'] === 'subir_porcentaje';
        $porcentaje = self::valor_legible($cambio['valor'], 'porcentaje');

        $conteo = PrecioFinalEnMasivaHelper::clasificar($articulos, (float) $cambio['valor'], $sube);

        if ($conteo['manual'] + $conteo['calculado'] === 0) {

            return RespuestaDeCargaIa::error($total === 1
                ? 'El artículo alcanzado no tiene un precio que ' . ($sube ? 'subir' : 'bajar') . ': no tiene costo ni precio cargado.'
                : 'Ninguno de los ' . $total . ' artículos tiene un precio que ' . ($sube ? 'subir' : 'bajar') . ': no tienen costo ni precio cargado.');
        }

        $como = '';

        if ($conteo['calculado'] > 0) {

            $como = 'En ' . self::cantidad_de_articulos($conteo['calculado']) . ' se ajusta el margen para que el precio final ' . ($sube ? 'suba ' : 'baje ') . $porcentaje;
        }

        if ($conteo['manual'] > 0) {

            $como .= $como === ''
                ? 'En ' . self::cantidad_de_articulos($conteo['manual']) . ($sube ? ' sube' : ' baja') . ' el precio manual'
                : ' y en ' . $conteo['manual'] . ($sube ? ' sube' : ' baja') . ' el precio manual';
        }

        $renglones = [
            ['etiqueta' => 'Cómo', 'valor' => $como],
        ];

        if ($conteo['sin_precio'] > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $conteo['sin_precio'] === 1
                    ? '1 no tiene costo ni precio cargado: no cambia'
                    : $conteo['sin_precio'] . ' no tienen costo ni precio cargado: no cambian',
            ];
        }

        if (!$sube && $conteo['margen_negativo'] > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $conteo['margen_negativo'] === 1
                    ? '1 quedaría con margen negativo'
                    : $conteo['margen_negativo'] . ' quedarían con margen negativo',
            ];
        }

        return $renglones;
    }

    /**
     * precio_final con listas de precio: cada lista sale del costo, así que se sube (o baja) el
     * costo. Los que no tienen costo no cambian.
     *
     * @param  \Illuminate\Support\Collection  $articulos
     * @param  array  $cambio  {campo, operacion, valor}
     * @return array  Los renglones o la respuesta negativa.
     */
    protected static function analizar_precio_final_con_listas($articulos, array $cambio)
    {
        $total = count($articulos);
        $sube = $cambio['operacion'] === 'subir_porcentaje';

        $con_costo = 0;

        foreach ($articulos as $articulo) {

            if (!is_null($articulo->cost) && (float) $articulo->cost > 0) {

                $con_costo++;
            }
        }

        if ($con_costo === 0) {

            return RespuestaDeCargaIa::error($total === 1
                ? 'El artículo alcanzado no tiene costo cargado: con listas de precio el precio sale del costo, así que no hay precio que ' . ($sube ? 'subir' : 'bajar') . '.'
                : 'Ninguno de los ' . $total . ' artículos tiene costo cargado: con listas de precio el precio sale del costo, así que no hay precio que ' . ($sube ? 'subir' : 'bajar') . '.');
        }

        $renglones = [
            [
                'etiqueta' => 'Cómo',
                'valor'    => 'Con listas de precio cada lista sale del costo: ' . ($sube ? 'sube' : 'baja') . ' el costo '
                    . self::valor_legible($cambio['valor'], 'porcentaje') . ' y los márgenes quedan iguales. Si después se actualiza el costo desde el proveedor, '
                    . ($sube ? 'el aumento' : 'la baja') . ' se reemplaza.',
            ],
        ];

        $sin_costo = $total - $con_costo;

        if ($sin_costo > 0) {

            $renglones[] = [
                'etiqueta' => 'Atención',
                'valor'    => $sin_costo === 1 ? '1 no tiene costo: no cambia' : $sin_costo . ' no tienen costo: no cambian',
            ];
        }

        return $renglones;
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

        return [
            'texto' => 'Actualización masiva encolada: ' . $encolados . ' artículos. Te va a aparecer en el sistema cuando termine.',
            'ruta'  => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
        ];
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
     * @param  array  $cambios
     * @param  int  $owner_id
     * @param  bool  $usa_listas  El comercio trabaja con listas de precio: precio_final se traduce
     *                            como subir o bajar el costo (ver cambio_precio_final()).
     * @return array  ['update_form' => [...], 'legibles' => [...]] o la respuesta negativa.
     */
    public static function traducir_cambios(array $cambios, $owner_id, $usa_listas = false)
    {
        $update_form = [];
        $legibles = [];

        foreach ($cambios as $cambio) {

            if (!is_array($cambio)) {

                return RespuestaDeCargaIa::error('Cada cambio tiene que venir como {campo, operacion, valor}.');
            }

            $campo = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'campo'));
            $operacion = mb_strtolower(EntradaDeCargaIa::texto($cambio, 'operacion'));
            $valor = EntradaDeCargaIa::valor($cambio, 'valor');

            if ($campo === self::CAMPO_PRECIO_FINAL) {

                $traducido = self::cambio_precio_final($operacion, $valor, !empty($cambio['redondear']), $usa_listas);

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

        return [
            'update_form' => $update_form,
            'legibles'    => $legibles,
        ];
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
     *  - Precio único: la operación de la masiva PrecioFinalEnMasivaHelper (`type` precio_final, con
     *    su `label` para el historial). `redondear` solo redondea los de precio manual: en los de
     *    costo + margen se ajusta el margen y el redondeo es el del comercio.
     *  - Listas de precio: el margen del artículo no mueve las listas, y cada lista sale del costo,
     *    así que se sube (o baja) el costo, sin redondeo.
     *
     * No se puede setear: el precio final se calcula. Para fijar un precio a mano está precio_manual.
     *
     * @param  string  $operacion
     * @param  mixed  $valor
     * @param  bool  $redondear
     * @param  bool  $usa_listas
     * @return array  ['form', 'texto'] o negativa.
     */
    protected static function cambio_precio_final($operacion, $valor, $redondear, $usa_listas)
    {
        if ($operacion === 'setear') {

            return RespuestaDeCargaIa::error('El precio final se calcula, no se fija: para precio_final la operación es subir_porcentaje o bajar_porcentaje. Para fijar un precio a mano es precio_manual, que solo manda en los artículos sin margen.');
        }

        if (!in_array($operacion, ['subir_porcentaje', 'bajar_porcentaje'], true)) {

            return RespuestaDeCargaIa::error('Para precio_final la operación es subir_porcentaje o bajar_porcentaje.');
        }

        if (!is_numeric($valor)) {

            return RespuestaDeCargaIa::error('El valor de Precio final tiene que ser un número.');
        }

        $numero = $valor + 0;
        $sube = $operacion === 'subir_porcentaje';

        if ($numero <= 0) {

            return RespuestaDeCargaIa::error('El porcentaje para subir o bajar Precio final tiene que ser mayor a 0.');
        }

        if (!$sube && $numero >= 100) {

            return RespuestaDeCargaIa::error('Para bajar el precio final el porcentaje tiene que ser menor a 100.');
        }

        if ($usa_listas) {

            return [
                'form'  => ['type' => 'number', 'key' => ($sube ? 'increment_' : 'decrement_') . 'cost', 'value' => $numero],
                'texto' => 'Costo' . ($sube ? ' sube ' : ' baja ') . self::valor_legible($numero, 'porcentaje'),
            ];
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
            'texto' => 'Precio final' . ($sube ? ' sube ' : ' baja ') . self::valor_legible($numero, 'porcentaje') . ($redondear ? ' (redondeado en los de precio manual)' : ''),
        ];
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
     * La unidad de medida por nombre, con la misma regla que las relaciones del filtro (única,
     * exacta entre varias, o `faltan` con opciones).
     *
     * @param  string  $texto
     * @return \App\Models\UnidadMedida|array
     */
    protected static function resolver_unidad_de_medida($texto)
    {
        $candidatas = UnidadMedida::where('name', 'LIKE', '%' . addcslashes($texto, '%_\\') . '%')
                                    ->orderBy('name')
                                    ->limit(20)
                                    ->get();

        if (count($candidatas) === 0) {

            return RespuestaDeCargaIa::error('No encontré ninguna unidad de medida que se llame "' . $texto . '".');
        }

        if (count($candidatas) === 1) {

            return $candidatas[0];
        }

        foreach ($candidatas as $candidata) {

            if (mb_strtolower(trim((string) $candidata->name)) === mb_strtolower($texto)) {

                return $candidata;
            }
        }

        $opciones = [];

        foreach ($candidatas as $candidata) {

            $opciones[] = ['id' => (int) $candidata->id, 'nombre' => (string) $candidata->name];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál unidad de medida: hay varias que encajan con "' . $texto . '"'],
            ['unidades_de_medida' => $opciones]
        );
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
            array_keys(self::CAMPOS_DE_CHECKBOX)
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
