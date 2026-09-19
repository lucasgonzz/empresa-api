<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
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

        $cambios_traducidos = self::traducir_cambios($cambios, $contexto->owner_id);

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

        $renglones = [
            ['etiqueta' => 'Artículos alcanzados', 'valor' => (string) $total],
        ];

        foreach ($traducido['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        foreach ($cambios_traducidos['legibles'] as $legible) {

            $renglones[] = ['etiqueta' => 'Cambio', 'valor' => $legible];
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

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Actualización masiva de ' . $total . ' artículos: ' . implode('; ', $cambios_traducidos['legibles']),
            [
                'articulos_alcanzados' => $total,
                'filtros_legibles'     => $traducido['legibles'],
                'cambios_legibles'     => $cambios_traducidos['legibles'],
            ]
        );
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
     * @return array  ['update_form' => [...], 'legibles' => [...]] o la respuesta negativa.
     */
    public static function traducir_cambios(array $cambios, $owner_id)
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

            if (isset(self::CAMPOS_NUMERICOS[$campo])) {

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
