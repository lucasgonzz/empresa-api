<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\expense\ExpenseHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use Carbon\Carbon;

/**
 * Gasto propuesto por el asistente de IA (misión asistente-ia-acciones, §3.4 del plan): validación al
 * proponer, tarjeta, datos de ejecución y ejecución al confirmar.
 *
 * Se ejecuta por ExpenseHelper::crear(), el MISMO camino que `POST api/expense` de la pantalla de
 * Gastos: una sola forma de dar de alta un gasto con su desglose y sus movimientos de caja.
 */
class PropuestaGastoIaHelper {

    /**
     * Qué hacer si la subcategoría que nombró la persona no está (misión asistente-altas-y-conversacion,
     * 16/10/2026). En demo3 el dueño pidió "creala vos, ponele servicios" y el asistente contestó "no
     * puedo crear categorías nuevas de gasto: eso se carga desde la pantalla de Gastos". Sí puede: es
     * una entidad más del ABM genérico. Esta respuesta no lo decía y el modelo inventó la negativa.
     */
    const SI_NO_ESTA = 'Si la subcategoría que nombró la persona no está en la lista, NO hace falta crearla antes con proponer_alta '
        . '(expense_concept / expense_category): volvé a llamar a proponer_gasto con subcategoria_nueva (su nombre) y categoria '
        . '(el nombre de la categoría de gasto, de las que tiene o una nueva) y armo el alta de las dos y el gasto en UNA sola tarjeta. '
        . 'No la mandes a la pantalla de Gastos.';

    /**
     * Herramienta proponer_gasto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  subcategoria_id (o subcategoria_nueva + categoria), monto*, fecha, moneda, pagos,
     *                        observaciones, importe_iva, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input) {

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::GASTOS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('gastos'));
        }

        $subcategoria_id = (int) EntradaDeCargaIa::valor($input, 'subcategoria_id');
        $monto = EntradaDeCargaIa::valor($input, 'monto');

        /*
         * 30/10 #3 (misión asistente-diario-20261116, rama B): la subcategoría (y su categoría) NUEVAS por
         * nombre. En Ferre Total la cadena «categoría → subcategoría → gasto» en tres tarjetas se cortó
         * después de la primera: quedó la categoría y nada más. Ahora va todo en UNA tarjeta.
         */
        $subcategoria_nueva = $subcategoria_id > 0 ? '' : EntradaDeCargaIa::texto($input, 'subcategoria_nueva');

        $faltan = [];

        if ($subcategoria_id <= 0 && $subcategoria_nueva === '') {

            $faltan[] = 'qué subcategoría de gasto es';
        }

        if (EntradaDeCargaIa::vacio($monto)) {

            $faltan[] = 'de cuánto fue el gasto';
        }

        if (count($faltan)) {

            $opciones = ($subcategoria_id <= 0 && $subcategoria_nueva === '')
                ? ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, ''), 'si_no_esta' => self::SI_NO_ESTA]
                : [];

            return RespuestaDeCargaIa::faltan($faltan, $opciones);
        }

        /* El alta encadenada (categoría y/o subcategoría nuevas) que va en la misma tarjeta, o null. */
        $alta = null;

        if ($subcategoria_nueva !== '') {

            $resuelta = self::resolver_subcategoria_por_nombre($contexto, $subcategoria_nueva, EntradaDeCargaIa::texto($input, 'categoria'));

            if (RespuestaDeCargaIa::es_negativa($resuelta)) {

                return $resuelta;
            }

            $concepto = $resuelta['concepto'];
            $alta = $resuelta['alta'];

        } else {

            $concepto = ExpenseConcept::where('user_id', $contexto->owner_id)
                                        ->where('id', $subcategoria_id)
                                        ->with('expense_category')
                                        ->first();
        }

        if (is_null($concepto) && is_null($alta)) {

            return RespuestaDeCargaIa::error(
                'Esa subcategoría de gasto no existe entre las tuyas.',
                ['subcategorias' => OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, ''), 'si_no_esta' => self::SI_NO_ESTA]
            );
        }

        $monto = EntradaDeCargaIa::monto_positivo($monto, 'El monto del gasto tiene que ser un número mayor a 0.');

        if (is_array($monto)) {

            return $monto;
        }

        $moneda_id = EntradaDeCargaIa::moneda($contexto, EntradaDeCargaIa::valor($input, 'moneda'));

        if (is_array($moneda_id)) {

            return $moneda_id;
        }

        $fecha = EntradaDeCargaIa::fecha($contexto, EntradaDeCargaIa::valor($input, 'fecha'));

        if (is_array($fecha)) {

            return $fecha;
        }

        $observaciones = EntradaDeCargaIa::texto($input, 'observaciones');
        $reemplaza_a = EntradaDeCargaIa::valor($input, 'reemplaza_a');

        /*
         * 🔴 Decisión 2 de Lucas: un gasto con fecha futura todavía NO es un gasto. Registrarlo hoy
         * movería la caja hoy por una plata que sale otro día. Se agenda como tarea con su gasto
         * asociado (subcategoría + monto estimado) y, ese día, al marcarla como hecha, se pregunta
         * cómo se pagó y se registra el gasto. Por eso acá no se piden los pagos.
         */
        if ($fecha->gt($contexto->hoy)) {

            /*
             * La tarea de un gasto futuro guarda el id de su subcategoría, y una subcategoría que
             * todavía no existe no tiene id: se dice qué hacer en vez de agendar algo a medias.
             */
            if (is_null($concepto)) {

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'un gasto con fecha futura se agenda sobre una subcategoría que ya existe, y "'
                    . $alta['subcategoria'] . '" todavía no existe. Primero creala con proponer_alta (expense_concept) o cargá el gasto con fecha de hoy.'
                );
            }

            return PropuestaTareaIaHelper::proponer_desde_carga_futura($contexto, $mensaje, [
                'detalle'            => $observaciones !== '' ? $observaciones : (string) $concepto->name,
                'fecha'              => $fecha,
                'expense_concept_id' => (int) $concepto->id,
                'expense_amount'     => $monto,
                'notas'              => null,
                'convertido_desde'   => AiMessageAction::TIPO_GASTO,
                'clave'              => 'tarea_nueva:gasto:'.$concepto->id,
                'reemplaza_a'        => $reemplaza_a,
            ]);
        }

        $importe_iva = 0;
        $iva_pedido = EntradaDeCargaIa::valor($input, 'importe_iva');

        if (!EntradaDeCargaIa::vacio($iva_pedido)) {

            if (!is_numeric($iva_pedido) || (float) $iva_pedido < 0) {

                return RespuestaDeCargaIa::error('El importe de IVA tiene que ser un número mayor o igual a 0.');
            }

            $importe_iva = round((float) $iva_pedido, 2);
        }

        // 30/10 #3: con el criterio de caja de la pantalla de Gastos (ver PagosIaHelper::validar()).
        $pagos = PagosIaHelper::validar($contexto, EntradaDeCargaIa::valor($input, 'pagos'), $monto, $moneda_id, true);

        if (RespuestaDeCargaIa::es_negativa($pagos)) {

            return $pagos;
        }

        $subcategoria = is_null($concepto) ? self::nombre_de_la_nueva($alta) : OpcionesDeCargaIaHelper::nombre_de_subcategoria($concepto);

        $renglones = [
            ['etiqueta' => 'Subcategoría', 'valor' => $subcategoria],
            ['etiqueta' => 'Monto', 'valor' => FormatoIaHelper::monto($monto, $moneda_id)],
        ];

        foreach ($pagos['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Fecha', 'valor' => FormatoIaHelper::fecha_con_dia($fecha)];

        if ($importe_iva > 0) {

            // El IVA de un gasto va SIEMPRE en pesos (descripción del campo en src/models/expense.js).
            $renglones[] = ['etiqueta' => 'IVA', 'valor' => FormatoIaHelper::monto($importe_iva)];
        }

        if ($observaciones !== '') {

            $renglones[] = ['etiqueta' => 'Observaciones', 'valor' => $observaciones];
        }

        $datos = [
            'expense_concept_id' => is_null($concepto) ? 0 : (int) $concepto->id,
            'amount'             => $monto,
            'moneda_id'          => $moneda_id,
            'importe_iva'        => $importe_iva,
            'observations'       => $observaciones !== '' ? $observaciones : null,
            'fecha'              => $fecha->format('Y-m-d'),
            'payment_methods'    => $pagos['filas'],
        ];

        /*
         * 30/10 #3: el alta de la subcategoría (y de su categoría, si tampoco existe), con la forma que
         * ejecuta EjecutorGenericoIaHelper. Clave nueva y opcional: una tarjeta vieja no la trae y se
         * ejecuta como siempre.
         */
        if (!is_null($alta)) {

            $datos['alta_de_subcategoria'] = $alta;
        }

        $avisos = array_values(array_filter([
            is_null($alta) ? null : 'Al confirmar creo '.self::que_se_crea($alta).' y cargo el gasto ahí, todo junto.',
            isset($pagos['aviso_de_caja']) ? $pagos['aviso_de_caja'] : null,
        ]));

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_GASTO,
            is_null($concepto) ? self::clave_de_nueva($alta) : self::clave_de_la_subcategoria($contexto, $concepto),
            $datos,
            ['titulo' => 'Gasto', 'renglones' => $renglones, 'aviso' => count($avisos) ? implode(' ', $avisos) : null],
            $reemplaza_a
        );

        $resumen = 'Gasto '.$subcategoria.' '.FormatoIaHelper::monto($monto, $moneda_id).' · '.$pagos['resumen'].' · '.FormatoIaHelper::fecha_con_dia($fecha);

        /*
         * Decisión del coordinador de la misión (revisión de la rama B): si la tarjeta CREA una
         * categoría o una subcategoría, espera el sí de la persona en todos los modos, también en
         * "directo". `requiere_confirmacion` lo respeta la puerta única
         * (HerramientasDeCarga::quizas_auto_confirmar); un gasto sobre una subcategoría que ya existe
         * sigue como siempre.
         */
        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, is_null($alta) ? [] : ['requiere_confirmacion' => true]);
    }

    /**
     * Identidad de un gasto para el reemplazo: una corrección del mismo gasto reemplaza la tarjeta,
     * y un gasto de otra subcategoría ("cargame también la nafta") no la pisa.
     *
     * @param  int  $expense_concept_id
     * @return string
     */
    static function clave($expense_concept_id) {

        return 'gasto:'.(int) $expense_concept_id;
    }

    /**
     * Clave de un gasto sobre una subcategoría que todavía no existe: por su nombre normalizado, para que
     * una corrección del mismo gasto reemplace la tarjeta (30/10 #3).
     *
     * @param  array  $alta
     * @return string
     */
    static function clave_de_nueva(array $alta) {

        return self::clave_por_nombre($alta['subcategoria']);
    }

    /**
     * La clave por nombre normalizado de la subcategoría: la de una tarjeta que la crea.
     *
     * @param  string  $nombre
     * @return string
     */
    protected static function clave_por_nombre($nombre) {

        return 'gasto:nueva:'.md5(self::normalizado($nombre));
    }

    /**
     * La clave de un gasto sobre una subcategoría que YA existe (revisión de la rama B, hallazgo 1).
     *
     * 🔴 La guarda de doble registro (el reemplazo y `confirmada_parecida` de AccionesIaHelper::crear) es
     * por clave. Si en esta conversación hubo una tarjeta que CREABA esta subcategoría (clave por nombre)
     * y después se vuelve a proponer el mismo gasto con la subcategoría ya creada (clave por id), las dos
     * claves no coincidían y el segundo gasto pasaba, en "directo", sin aviso: el mismo gasto dos veces.
     * Por eso, si existe esa tarjeta por nombre en la conversación, se usa SU clave; si no, la de siempre
     * (`gasto:<id>`, la que leen los tests y las tarjetas viejas).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\ExpenseConcept  $concepto
     * @return string
     */
    protected static function clave_de_la_subcategoria(ContextoDeCargaIa $contexto, ExpenseConcept $concepto) {

        $por_nombre = self::clave_por_nombre($concepto->name);

        $hubo_una_que_la_creaba = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                                ->where('tipo', AiMessageAction::TIPO_GASTO)
                                                ->where('clave', $por_nombre)
                                                ->exists();

        return $hubo_una_que_la_creaba ? $por_nombre : self::clave($concepto->id);
    }

    /**
     * La subcategoría que la persona nombró como NUEVA (30/10 #3), resuelta: si ya existe una con ese
     * nombre (sin importar mayúsculas, tildes ni puntuación) se usa ésa y no se crea nada; si no, se arma
     * su alta y la de su categoría, si tampoco existe.
     *
     * Las altas se arman con las MISMAS piezas que proponer_alta (PropuestaGenericaIaHelper::entidad_para,
     * validar_campos y payload_de_alta) y al confirmar las ejecuta EjecutorGenericoIaHelper, que llama a
     * ExpenseCategoryController::store() y ExpenseConceptController::store() como la pantalla del ABM.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $subcategoria  El nombre de la subcategoría nueva.
     * @param  string  $categoria  El nombre de su categoría (existente o nueva); vacío si no vino.
     * @return array  ['concepto' => ExpenseConcept|null, 'alta' => array|null] o la respuesta negativa.
     */
    protected static function resolver_subcategoria_por_nombre(ContextoDeCargaIa $contexto, $subcategoria, $categoria) {

        $subcategoria = trim((string) $subcategoria);
        $categoria = trim((string) $categoria);

        $existente = self::subcategoria_existente($contexto->owner_id, $subcategoria);

        if (!is_null($existente)) {

            return ['concepto' => $existente, 'alta' => null];
        }

        if ($categoria === '') {

            return RespuestaDeCargaIa::faltan(
                ['en qué categoría de gasto va la subcategoría nueva "'.$subcategoria.'" (una de las que tiene o una nueva)'],
                ['categorias' => self::nombres_de_categorias($contexto->owner_id)]
            );
        }

        $categoria_existente = null;

        foreach (ExpenseCategory::where('user_id', $contexto->owner_id)->get(['id', 'name']) as $fila) {

            if (self::normalizado($fila->name) === self::normalizado($categoria)) {

                $categoria_existente = $fila;

                break;
            }
        }

        $alta_categoria = null;

        if (is_null($categoria_existente)) {

            $alta_categoria = self::alta_generica($contexto, 'expense_category', $categoria);

            if (RespuestaDeCargaIa::es_negativa($alta_categoria)) {

                return $alta_categoria;
            }
        }

        $alta_concepto = self::alta_generica($contexto, 'expense_concept', $subcategoria);

        if (RespuestaDeCargaIa::es_negativa($alta_concepto)) {

            return $alta_concepto;
        }

        return [
            'concepto' => null,
            'alta'     => [
                'subcategoria'        => $subcategoria,
                'categoria'           => is_null($categoria_existente) ? $categoria : (string) $categoria_existente->name,
                'expense_category_id' => is_null($categoria_existente) ? null : (int) $categoria_existente->id,
                'alta_categoria'      => $alta_categoria,
                'alta_concepto'       => $alta_concepto,
            ],
        ];
    }

    /**
     * El alta de una entidad del ABM genérico con solo su nombre, con la forma que lee
     * EjecutorGenericoIaHelper (mismo armado que PropuestaCompraConFacturaIaHelper::alta_del_proveedor()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $entidad  expense_category | expense_concept
     * @param  string  $nombre
     * @return array  [entidad, operacion, payload, pedidos] o la respuesta negativa.
     */
    protected static function alta_generica(ContextoDeCargaIa $contexto, $entidad, $nombre) {

        $declaracion = PropuestaGenericaIaHelper::entidad_para($contexto, $entidad, Catalogo::OP_ALTA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return RespuestaDeCargaIa::error('No puedo crear "'.$nombre.'": '.$declaracion['error']);
        }

        $validado = PropuestaGenericaIaHelper::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, ['name' => $nombre]);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            return $validado;
        }

        return [
            'entidad'   => $declaracion['entidad'],
            'operacion' => Catalogo::OP_ALTA,
            'payload'   => PropuestaGenericaIaHelper::payload_de_alta($declaracion, $validado['payload']),
            'pedidos'   => $validado['pedidos'],
        ];
    }

    /**
     * La subcategoría del dueño que ya se llama así (normalizado), o null.
     *
     * @param  int  $owner_id
     * @param  string  $nombre
     * @return \App\Models\ExpenseConcept|null
     */
    protected static function subcategoria_existente($owner_id, $nombre) {

        $buscado = self::normalizado($nombre);

        if ($buscado === '') {

            return null;
        }

        foreach (ExpenseConcept::where('user_id', (int) $owner_id)->with('expense_category')->get() as $concepto) {

            if (self::normalizado($concepto->name) === $buscado) {

                return $concepto;
            }
        }

        return null;
    }

    /**
     * Los nombres de las categorías de gasto del dueño, para ofrecerlas.
     *
     * @param  int  $owner_id
     * @return array<int, array{id: int, nombre: string}>
     */
    protected static function nombres_de_categorias($owner_id) {

        $opciones = [];

        foreach (ExpenseCategory::where('user_id', (int) $owner_id)->orderBy('name')->limit(50)->get(['id', 'name']) as $categoria) {

            $opciones[] = ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->name];
        }

        return $opciones;
    }

    /**
     * Un nombre comparable: minúsculas, sin tildes ni puntuación (el normalizador de los proveedores, sin
     * sacar sufijos societarios).
     *
     * @param  string|null  $texto
     * @return string
     */
    protected static function normalizado($texto) {

        return ProveedorDeLaFacturaIaHelper::normalizar((string) $texto, false);
    }

    /**
     * El renglón «Subcategoría» de una nueva: «Impuesto al cheque (nueva, en la categoría nueva Gastos bancarios)».
     *
     * @param  array  $alta
     * @return string
     */
    protected static function nombre_de_la_nueva(array $alta) {

        return $alta['subcategoria'].' (nueva, en la categoría '.(is_null($alta['alta_categoria']) ? '' : 'nueva ').$alta['categoria'].')';
    }

    /**
     * Lo que se crea al confirmar, en palabras, para el aviso de la tarjeta.
     *
     * @param  array  $alta
     * @return string
     */
    protected static function que_se_crea(array $alta) {

        if (is_null($alta['alta_categoria'])) {

            return 'la subcategoría "'.$alta['subcategoria'].'" en "'.$alta['categoria'].'"';
        }

        return 'la categoría "'.$alta['categoria'].'" con la subcategoría "'.$alta['subcategoria'].'"';
    }

    /**
     * Crea (o reusa) la categoría y la subcategoría del alta encadenada, adentro de la transacción del
     * gasto: si después el gasto no se puede registrar, el rollback se lleva también las altas, y nunca
     * queda una categoría suelta de un gasto que no existe (lo que pasó en Ferre Total el 30/10).
     *
     * 🔴 Se vuelve a mirar si ya existen: entre la tarjeta y el sí alguien pudo crearlas desde la pantalla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $alta
     * @return int  El id de la subcategoría.
     *
     * @throws AccionIaException
     */
    protected static function crear_lo_que_falta(ContextoDeCargaIa $contexto, array $alta) {

        $existente = self::subcategoria_existente($contexto->owner_id, $alta['subcategoria']);

        if (!is_null($existente)) {

            return (int) $existente->id;
        }

        $categoria_id = isset($alta['expense_category_id']) ? (int) $alta['expense_category_id'] : 0;

        if ($categoria_id <= 0) {

            foreach (ExpenseCategory::where('user_id', $contexto->owner_id)->get(['id', 'name']) as $fila) {

                if (self::normalizado($fila->name) === self::normalizado($alta['categoria'])) {

                    $categoria_id = (int) $fila->id;

                    break;
                }
            }
        }

        if ($categoria_id <= 0) {

            if (empty($alta['alta_categoria'])) {

                throw new AccionIaException(422, 'La categoría de gasto de la tarjeta ya no existe. Pedímelo de nuevo.');
            }

            $categoria_id = self::ejecutar_alta($contexto, $alta['alta_categoria'], ExpenseCategory::class);
        }

        $alta_concepto = $alta['alta_concepto'];
        $alta_concepto['payload']['expense_category_id'] = $categoria_id;
        $alta_concepto['pedidos']['expense_category_id'] = $categoria_id;

        return self::ejecutar_alta($contexto, $alta_concepto, ExpenseConcept::class);
    }

    /**
     * Ejecuta un alta del ABM genérico por EjecutorGenericoIaHelper (el controller de la pantalla) y
     * devuelve el id creado, verificado contra el dueño.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $datos_del_alta
     * @param  string  $modelo  Clase del modelo, para verificar el id.
     * @return int
     *
     * @throws AccionIaException
     */
    protected static function ejecutar_alta(ContextoDeCargaIa $contexto, array $datos_del_alta, $modelo) {

        /* Una tarjeta de alta "de mentira", sin guardar: el mismo camino que proponer_alta. */
        $accion = new AiMessageAction();
        $accion->tipo = AiMessageAction::TIPO_ALTA;
        $accion->datos = $datos_del_alta;

        $resultado = EjecutorGenericoIaHelper::ejecutar($contexto, $accion);

        $id = isset($resultado['id']) ? (int) $resultado['id'] : 0;

        if ($id <= 0 || !$modelo::where('user_id', $contexto->owner_id)->where('id', $id)->exists()) {

            throw new AccionIaException(422, 'No se pudo crear la categoría o la subcategoría del gasto. No se cargó nada.');
        }

        return $id;
    }

    /**
     * Registra el gasto de la tarjeta. Corre adentro de la transacción de EjecutorAccionesIaHelper,
     * autenticado como la persona que confirma.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @param  callable  $num_expense_resolver  Controller::num('expenses'), resuelto adentro de la transacción.
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion, $num_expense_resolver) {

        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::GASTOS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('gastos'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $expense_concept_id = isset($datos['expense_concept_id']) ? (int) $datos['expense_concept_id'] : 0;

        // 30/10 #3: la categoría y/o subcategoría nuevas de la misma tarjeta, antes del gasto.
        if (!empty($datos['alta_de_subcategoria']) && is_array($datos['alta_de_subcategoria'])) {

            $expense_concept_id = self::crear_lo_que_falta($contexto, $datos['alta_de_subcategoria']);
        }

        if (!ExpenseConcept::where('user_id', $contexto->owner_id)->where('id', $expense_concept_id)->exists()) {

            throw new AccionIaException(422, 'La subcategoría de gasto de la tarjeta ya no existe. Pedímelo de nuevo.');
        }

        $payment_methods = isset($datos['payment_methods']) && is_array($datos['payment_methods']) ? $datos['payment_methods'] : [];

        // Mismo 422 que ExpenseController::store() para una caja que nunca se abrió.
        PagosIaHelper::verificar_para_ejecutar($contexto, $payment_methods, 'el gasto');

        $fecha = Carbon::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay();
        $ahora = Carbon::now();

        /*
         * La hora del gasto: si la fecha es hoy, ahora; si es anterior, ese día con la hora actual
         * (el formulario de Gastos manda solo el día y el listado ordena por created_at).
         */
        $created_at = $fecha->gte($contexto->hoy)
            ? $ahora->format('Y-m-d H:i:s')
            : $fecha->format('Y-m-d').' '.$ahora->format('H:i:s');

        $gasto = ExpenseHelper::crear([
            'expense_concept_id' => $expense_concept_id,
            'amount'             => isset($datos['amount']) ? (float) $datos['amount'] : 0,
            'moneda_id'          => ExpenseHelper::normalizar_moneda_id(isset($datos['moneda_id']) ? $datos['moneda_id'] : null),
            'importe_iva'        => isset($datos['importe_iva']) ? (float) $datos['importe_iva'] : 0,
            'observations'       => isset($datos['observations']) ? $datos['observations'] : null,
            'created_at'         => $created_at,
            'payment_methods'    => $payment_methods,
        ], $contexto->owner_id, $num_expense_resolver);

        /*
         * `fecha` (Y-m-d) es el día que tiene que mostrar Gastos para que se vea el gasto: el
         * listado es por día (from-date) y un gasto con fecha anterior queda en ESE día, no en hoy.
         * La lee la SPA al tocar "Ver en Gastos" (ai_chat/refrescarPantallaDeLaAccion); es una
         * clave nueva y opcional: una SPA vieja la ignora, y una SPA nueva con una tarjeta vieja
         * (sin `fecha`) abre hoy (misión ver-en-del-asistente-refresca-destino, 10/10/2026).
         */
        return [
            'texto' => 'Gasto N° '.$gasto->num.' registrado',
            'ruta'  => [
                'name'   => 'expense',
                'params' => new \stdClass(),
                'texto'  => 'Ver en Gastos',
                'fecha'  => substr($created_at, 0, 10),
            ],
        ];
    }
}
