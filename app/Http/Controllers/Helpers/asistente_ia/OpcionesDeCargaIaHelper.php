<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\Address;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\ExpenseConcept;
use App\Models\UnidadFrecuencia;

/**
 * Las opciones con las que el asistente de IA puede armar una carga: cajas, caja por defecto,
 * métodos de pago, subcategorías de gasto y unidades de repetición (misión asistente-ia-acciones,
 * 15/9/2026).
 *
 * 🔴 LAS CAJAS SON UN ESPEJO LITERAL DE LOS DESPLEGABLES DE LA PANTALLA. Si el asistente ofreciera
 * una caja que el desplegable de Gastos o del Pago no ofrece (o eligiera otra por defecto), la
 * persona confirmaría una carga que desde la pantalla no podría hacer: es la clase "el mismo
 * invariante con dos criterios en front y back" de APRENDER_NO_PARCHEAR.md. Las tres pantallas que
 * cargan plata con caja (Pago de cuenta corriente, Gastos y marcar hecha una tarea de la Agenda) usan
 * el mismo componente, src/components/common/payment-methods/PaymentMethodsStep.vue, y de ahí salen
 * los criterios que se copian acá, citados método por método. Si la SPA cambia uno, este archivo
 * cambia en el mismo diff.
 *
 * La "sucursal" de la pantalla es `vender.address_id`, que start_methods.js inicializa con el
 * `address_id` de la persona autenticada (y, si no tiene, con una cookie del navegador que el
 * servidor no puede ver). Por eso acá la sucursal es `persona.address_id`, y sin eso "todas".
 */
class OpcionesDeCargaIaHelper {

    /**
     * Tope de filas de una búsqueda: acota el JSON que viaja al prompt, no el negocio.
     */
    const TOPE = 20;

    /**
     * Id del método de pago que la pantalla trata aparte en la regla de caja obligatoria:
     * PaymentMethodsStep.vue::show_caja_select() no le dibuja el selector de caja y
     * `hay_metodo_de_pago_sin_caja()` no se la exige. En el catálogo de
     * CurrentAcountPaymentMethodSeeder es el Cheque (tipo cheque). Ver motivo_no_usable().
     */
    const METODO_SIN_CAJA_ID = 1;

    /**
     * Slug del tipo de método de pago "Cheque" (`c_a_payment_method_types`, CAPaymentMethodTypeSeeder).
     * Es el que `PaymentMethodHelper::attach_payment_methods()` mira para llamar a
     * `ChequeHelper::crear_cheque()`.
     */
    const SLUG_CHEQUE = 'cheque';

    /**
     * Tipos de método de pago que el asistente NO carga: la tarjeta de crédito pide recargo y
     * cuotas, y la retención los datos del certificado. Se cargan desde la pantalla.
     *
     * ⚠️ EL CHEQUE SALIÓ DE ESTA LISTA EL 22/9/2026 (misión asistente-capacidades-y-hilos). Estaba
     * acá porque "pide banco, fecha de cobro y número" — datos que el asistente no tenía forma de
     * pedir. Ahora sí: `PagosIaHelper` los valida y los manda en la fila, y el camino de ejecución
     * ya los soportaba sin tocar una línea (`ChequeHelper::crear_cheque` los lee del payload). El
     * mensaje #46 del 22/9 en demo3 —"los pagos con cheque no los puedo cargar desde acá"— era
     * exactamente este renglón.
     *
     * @var array<string,string>
     */
    const MOTIVOS_NO_USABLES = [
        'tarjeta_de_credito' => 'Los cobros con tarjeta de crédito (recargo y cuotas) se cargan desde la pantalla.',
        /*
         * La retención pide los datos del certificado que da el cliente (impuesto, número, fecha,
         * régimen), y el asistente no los tiene: armar la fila sin ellos guardaría un certificado
         * en blanco que después nadie completa. Sumado a que PagosIaHelper le pone caja a toda
         * fila con monto, y una retención no entra a ninguna caja.
         */
        'retencion'          => 'Las retenciones se cargan desde la pantalla, con los datos del certificado.',
    ];

    /**
     * Slugs de UnidadFrecuenciaSeeder y cómo los nombra el asistente.
     *
     * @var array<string,string>
     */
    const UNIDADES = [
        'day'   => 'dia',
        'week'  => 'semana',
        'month' => 'mes',
        'year'  => 'año',
    ];

    /**
     * Respuesta de la herramienta consultar_opciones_de_carga: todo lo que la IA necesita para
     * armar una carga sin inventar ids.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return array
     */
    static function opciones_de_carga(ContextoDeCargaIa $contexto) {

        $metodos = self::metodos_de_pago();
        $cajas = self::cajas_de_la_cuenta($contexto->owner_id);
        $calles = self::calles_de_sucursales($contexto->owner_id);

        $monedas = $contexto->usa_dolares ? [1, FormatoIaHelper::MONEDA_DOLARES] : [1];

        // Sin moneda get_caja_options() no filtra por moneda: son todas las que la persona puede usar.
        $ofrecibles = [];

        foreach (self::cajas_ofrecibles($contexto, null, $cajas) as $caja) {

            $ofrecibles[] = self::opcion_de_caja($caja, $calles);
        }

        $por_defecto = [];

        foreach ($metodos as $metodo) {

            if (!is_null(self::motivo_no_usable($metodo))) {

                continue;
            }

            foreach ($monedas as $moneda_id) {

                $caja = self::caja_por_defecto($contexto, $metodo->id, $moneda_id, self::cajas_ofrecibles($contexto, $moneda_id, $cajas), $cajas);

                if (!is_null($caja)) {

                    $por_defecto[] = [
                        'metodo_de_pago_id' => (int) $metodo->id,
                        'moneda'            => FormatoIaHelper::nombre_de_moneda($moneda_id),
                        'caja_id'           => (int) $caja->id,
                    ];
                }
            }
        }

        return [
            'hoy'                    => FormatoIaHelper::fecha_con_dia($contexto->hoy),
            'puede'                  => [
                'gastos'              => PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::GASTOS),
                'tareas'              => PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::TAREAS),
                'pagos_de_clientes'   => PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::PAGOS_DE_CLIENTES),
                'pagos_a_proveedores' => PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::PAGOS_A_PROVEEDORES),
            ],
            'ventas_en_dolares'      => (bool) $contexto->usa_dolares,
            'metodos_de_pago'        => self::opciones_de_metodos($metodos),
            'cajas'                  => $ofrecibles,
            'cajas_por_defecto'      => $por_defecto,
            'unidades_de_repeticion' => self::unidades_de_repeticion(),
        ];
    }

    /**
     * Las cajas de la cuenta, en el orden de `store.caja.models` (CajaController@index: created_at
     * DESC; el id desempata para que el orden no dependa del motor).
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    static function cajas_de_la_cuenta($owner_id) {

        return Caja::where('user_id', $owner_id)
                    ->orderBy('created_at', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->with('users', 'employee')
                    ->get();
    }

    /**
     * true si la cuenta tiene al menos una caja creada. Sin ninguna, la pantalla carga el pago sin
     * caja (el select ni se dibuja: PaymentMethodsStep::show_caja_select() pide `cajas.length`).
     *
     * @param  int  $owner_id
     * @return bool
     */
    static function hay_cajas($owner_id) {

        return Caja::where('user_id', $owner_id)->exists();
    }

    /**
     * Cajas que ofrece el desplegable para una moneda. Espejo LITERAL de
     * `get_caja_options(payment_method_id, address_id, moneda_id)` de src/mixins/generals.js:
     *   1. `store.caja.models.filter(caja => caja.abierta)`.
     *   2. `if (address_id)`: si la caja tiene sucursal, tiene que ser la de la pantalla; sin
     *      sucursal, sirve para todas.
     *   3. `if (moneda_id)`: `caja.moneda_id !== null ? caja.moneda_id == moneda_id : true`.
     *   4. Acceso: `is_admin` → todas; si la caja tiene `users`, la persona tiene que estar; sin
     *      `users`, todas.
     * El primer argumento del original (el método de pago) no filtra nada y por eso no está.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int|null  $moneda_id  null o 0 = sin filtro de moneda.
     * @param  \Illuminate\Support\Collection|null  $cajas  Las de cajas_de_la_cuenta(), si ya se leyeron.
     * @return array<int,\App\Models\Caja>
     */
    static function cajas_ofrecibles(ContextoDeCargaIa $contexto, $moneda_id, $cajas = null) {

        if (is_null($cajas)) {

            $cajas = self::cajas_de_la_cuenta($contexto->owner_id);
        }

        $address_id = self::address_id_de_la_persona($contexto->persona);
        $es_admin = PermisosIaHelper::es_admin($contexto->persona);
        $persona_id = is_null($contexto->persona) ? null : (int) $contexto->persona->id;

        $ofrecibles = [];

        foreach ($cajas as $caja) {

            if (empty($caja->abierta)) {

                continue;
            }

            if (!empty($address_id) && !empty($caja->address_id) && (int) $caja->address_id !== (int) $address_id) {

                continue;
            }

            if (!empty($moneda_id) && !is_null($caja->moneda_id) && (int) $caja->moneda_id !== (int) $moneda_id) {

                continue;
            }

            if (!$es_admin && count($caja->users)) {

                $tiene_acceso = false;

                foreach ($caja->users as $usuario) {

                    if ((int) $usuario->id === $persona_id) {

                        $tiene_acceso = true;
                    }
                }

                if (!$tiene_acceso) {

                    continue;
                }
            }

            $ofrecibles[] = $caja;
        }

        return $ofrecibles;
    }

    /**
     * Caja que la pantalla propone por defecto para un método y una moneda, o null.
     *
     * Espeja `set_caja_por_defecto()` de PaymentMethodsStep.vue, que usa `get_caja_por_defecto()`
     * de src/mixins/caja_por_defecto.js (el mixin local le gana al homónimo global de generals.js):
     *   - Sin sucursal se prueban las DOS representaciones de "ninguna" (null y después 0), porque
     *     en el parque conviven configuraciones guardadas con NULL y con 0.
     *   - Una caja propuesta de OTRA sucursal que la de la pantalla no se propone.
     * Y suma una condición que la pantalla no tiene (plan §3.2, hallazgo 1 del informe
     * 20260821-pago-cc-multimoneda-caja): la caja por defecto tiene que estar entre las ofrecibles.
     * La pantalla hoy puede dejar cargada una caja que su propio desplegable no muestra; acá eso no
     * se replica y, sin una caja ofrecible, el asistente pregunta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $metodo_id
     * @param  int|null  $moneda_id
     * @param  array<int,\App\Models\Caja>  $ofrecibles  cajas_ofrecibles() de esa moneda.
     * @param  \Illuminate\Support\Collection|null  $cajas
     * @return \App\Models\Caja|null
     */
    static function caja_por_defecto(ContextoDeCargaIa $contexto, $metodo_id, $moneda_id, array $ofrecibles, $cajas = null) {

        if (is_null($cajas)) {

            $cajas = self::cajas_de_la_cuenta($contexto->owner_id);
        }

        // Mismo orden que `store.default_payment_method_caja.models` (DefaultPaymentMethodCajaController@index).
        $configuraciones = DefaultPaymentMethodCaja::where('user_id', $contexto->owner_id)
                                                    ->orderBy('created_at', 'DESC')
                                                    ->orderBy('id', 'DESC')
                                                    ->get();

        $address_id = self::address_id_de_la_persona($contexto->persona);

        $caja = self::resolver_caja_por_defecto($contexto, $configuraciones, $cajas, $metodo_id, $address_id, $moneda_id);

        if (is_null($caja) && empty($address_id)) {

            $otra_representacion = $address_id === 0 ? null : 0;

            $caja = self::resolver_caja_por_defecto($contexto, $configuraciones, $cajas, $metodo_id, $otra_representacion, $moneda_id);
        }

        if (is_null($caja)) {

            return null;
        }

        if (!empty($address_id) && !empty($caja->address_id) && (int) $caja->address_id !== (int) $address_id) {

            return null;
        }

        foreach ($ofrecibles as $ofrecible) {

            if ((int) $ofrecible->id === (int) $caja->id) {

                return $caja;
            }
        }

        return null;
    }

    /**
     * Espejo LITERAL de `get_caja_por_defecto(method_id, address_id, moneda_id)` de
     * src/mixins/caja_por_defecto.js:
     *   1. Configuraciones con ese método y esa sucursal (comparación suelta `==` de JS: null solo
     *      es igual a null, y 0 no es igual a null).
     *   2. Sus cajas; si alguna es del empleado (`caja.employee_id == user.id`), solo esas.
     *   3. Con ventas en dólares y una moneda: primero las de esa moneda exacta, y si no hay, las de
     *      moneda null (segundo plato, no empate).
     *   4. Se prefieren las abiertas; si ninguna está abierta, la primera candidata igual.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \Illuminate\Support\Collection  $configuraciones
     * @param  \Illuminate\Support\Collection  $cajas
     * @param  int  $metodo_id
     * @param  int|null  $address_id
     * @param  int|null  $moneda_id
     * @return \App\Models\Caja|null
     */
    protected static function resolver_caja_por_defecto(ContextoDeCargaIa $contexto, $configuraciones, $cajas, $metodo_id, $address_id, $moneda_id) {

        $cajas_por_defecto = [];

        foreach ($configuraciones as $configuracion) {

            if (!self::igual_suelto($configuracion->current_acount_payment_method_id, $metodo_id)
                || !self::igual_suelto($configuracion->address_id, $address_id)) {

                continue;
            }

            foreach ($cajas as $caja) {

                if ((int) $caja->id === (int) $configuracion->caja_id) {

                    $cajas_por_defecto[] = $caja;
                    break;
                }
            }
        }

        $persona_id = is_null($contexto->persona) ? null : (int) $contexto->persona->id;

        $de_la_persona = [];

        foreach ($cajas_por_defecto as $caja) {

            if (!empty($caja->employee_id) && (int) $caja->employee_id === $persona_id) {

                $de_la_persona[] = $caja;
            }
        }

        if (count($de_la_persona)) {

            $cajas_por_defecto = $de_la_persona;
        }

        if (!count($cajas_por_defecto)) {

            return null;
        }

        $candidatas = $cajas_por_defecto;

        if ($contexto->usa_dolares && !empty($moneda_id)) {

            $de_la_moneda = [];
            $sin_moneda = [];

            foreach ($cajas_por_defecto as $caja) {

                if (!is_null($caja->moneda_id) && (int) $caja->moneda_id === (int) $moneda_id) {

                    $de_la_moneda[] = $caja;
                }

                if (is_null($caja->moneda_id)) {

                    $sin_moneda[] = $caja;
                }
            }

            $candidatas = count($de_la_moneda) ? $de_la_moneda : $sin_moneda;
        }

        foreach ($candidatas as $caja) {

            if (!empty($caja->abierta)) {

                return $caja;
            }
        }

        return count($candidatas) ? $candidatas[0] : null;
    }

    /**
     * Opción de caja para la IA: el nombre es el mismo texto que muestra el desplegable.
     *
     * @param  \App\Models\Caja  $caja
     * @param  array<int,string>  $calles  calles_de_sucursales().
     * @return array
     */
    static function opcion_de_caja($caja, array $calles) {

        $address_id = (int) $caja->address_id;

        return [
            'id'       => (int) $caja->id,
            'nombre'   => self::nombre_de_caja($caja, $calles),
            'moneda'   => is_null($caja->moneda_id) ? 'cualquiera' : FormatoIaHelper::nombre_de_moneda($caja->moneda_id),
            'sucursal' => $address_id > 0 && isset($calles[$address_id]) ? $calles[$address_id] : null,
        ];
    }

    /**
     * Texto de la caja en el desplegable. Espejo del formateo de nombres de get_caja_options():
     * nombre, "(empleado)" si es de un empleado, "(USD)" si es en dólares y "(calle)" si tiene
     * sucursal.
     *
     * @param  \App\Models\Caja  $caja
     * @param  array<int,string>  $calles
     * @return string
     */
    static function nombre_de_caja($caja, array $calles) {

        $nombre = (string) $caja->name;

        if (!is_null($caja->employee)) {

            $nombre .= ' ('.$caja->employee->name.')';
        }

        if ((int) $caja->moneda_id === FormatoIaHelper::MONEDA_DOLARES) {

            $nombre .= ' (USD)';
        }

        $address_id = (int) $caja->address_id;

        if ($address_id > 0 && isset($calles[$address_id])) {

            $nombre .= ' ('.$calles[$address_id].')';
        }

        return $nombre;
    }

    /**
     * Calle de cada sucursal de la cuenta (`addresses.street` es el nombre que muestra el sistema).
     *
     * @param  int  $owner_id
     * @return array<int,string>
     */
    static function calles_de_sucursales($owner_id) {

        $calles = [];

        foreach (Address::where('user_id', $owner_id)->get(['id', 'street']) as $address) {

            $calles[(int) $address->id] = (string) $address->street;
        }

        return $calles;
    }

    /**
     * El catálogo de métodos de pago que usa la pantalla (CurrentAcountPaymentMethodController@index:
     * tabla global, created_at DESC, con su tipo).
     *
     * @return \Illuminate\Support\Collection
     */
    static function metodos_de_pago() {

        return CurrentAcountPaymentMethod::orderBy('created_at', 'DESC')
                                            ->orderBy('id', 'DESC')
                                            ->withAll()
                                            ->get();
    }

    /**
     * Por qué el asistente no puede cargar con ese método, o null si puede.
     *
     * Primero el tipo, que da el motivo verdadero: el cheque pide banco, fecha de cobro y número, y
     * la tarjeta de crédito recargo y cuotas. Esas cargas se hacen desde la pantalla.
     *
     * 🔴 Y el método de id 1 queda afuera SIEMPRE, tenga o no su tipo cargado, porque la pantalla lo
     * trata aparte en las dos puntas de la regla de "caja obligatoria":
     * PaymentMethodsStep.vue::show_caja_select() no le dibuja el selector de caja, y
     * `hay_metodo_de_pago_sin_caja()` de src/mixins/metodos_de_pago_validacion.js (develop, misión
     * agenda-ajustes-ux) no le exige caja. Una fila con monto y sin caja es plata que no impacta en
     * ninguna caja (el backend solo loguea un warning), así que el asistente no la propone nunca. En
     * el catálogo de CurrentAcountPaymentMethodSeeder el id 1 es el Cheque (tipo cheque), así que en
     * una base normal ya quedó afuera por el tipo, con el motivo del cheque; la regla por id cubre una
     * base donde ese tipo no esté cargado.
     *
     * @param  \App\Models\CurrentAcountPaymentMethod  $metodo  Con `type` cargado.
     * @return string|null
     */
    static function motivo_no_usable($metodo) {

        if (!is_null($metodo->type) && isset(self::MOTIVOS_NO_USABLES[$metodo->type->slug])) {

            return self::MOTIVOS_NO_USABLES[$metodo->type->slug];
        }

        /*
         * 🔴 Y LA REGLA POR ID YA NO ALCANZA AL CHEQUE (misión asistente-capacidades-y-hilos,
         * 22/9/2026). La regla existe porque una fila con monto y sin caja es plata que no impacta
         * en ninguna caja — pero para un CHEQUE eso no es un defecto, es lo correcto: un cheque no
         * mueve caja al cargarse (la plata se mueve recién con `PUT /cheque/cobrar` o `/pagar`), y
         * por eso mismo la pantalla no le dibuja el selector de caja. El id 1 del catálogo es
         * justamente el Cheque, así que sin esta excepción la capacidad nueva quedaba muerta por
         * una regla escrita para otra cosa. El resto de los métodos sin caja siguen afuera.
         */
        if ((int) $metodo->id === self::METODO_SIN_CAJA_ID && !self::es_cheque($metodo)) {

            return 'Ese método de pago se carga desde la pantalla.';
        }

        return null;
    }

    /**
     * Por qué el asistente no puede cobrar UNA VENTA con ese método, o null si puede.
     *
     * 🔴 ES LA MISMA REGLA MÁS UNA: EL CHEQUE, QUE SÍ SE PUEDE EN UN PAGO PERO NO EN UNA VENTA
     * (misión asistente-capacidades-y-hilos, 22/9/2026). El motivo no es una preferencia: el cobro
     * de una venta viaja por el camino del método ÚNICO del select —`current_acount_payment_method_id`
     * y `caja_id`, con `selected_payment_methods` vacío—, que NO tiene dónde poner el número, el
     * banco ni las fechas del cheque. `attach_payment_methods()` crea el cheque igual, porque solo
     * mira el slug del tipo, y todas las columnas de `cheques` son nullable: quedaría un cheque en
     * blanco, sin número ni banco, imposible de reconciliar y sin ningún error en ningún lado. Un
     * pago de cuenta corriente sí puede, porque ahí la fila lleva esos datos (PagosIaHelper::
     * fila_de_cheque).
     *
     * @param  \App\Models\CurrentAcountPaymentMethod  $metodo  Con `type` cargado.
     * @return string|null
     */
    static function motivo_no_usable_en_venta($metodo) {

        $motivo = self::motivo_no_usable($metodo);

        if (!is_null($motivo)) {

            return $motivo;
        }

        if (self::es_cheque($metodo)) {

            return 'Una venta cobrada con cheque se hace desde Vender: el cobro de la venta no lleva el número, el banco ni la fecha del cheque, y quedaría un cheque en blanco. Un PAGO de cuenta corriente con cheque sí lo puedo cargar.';
        }

        return null;
    }

    /**
     * true si el método de pago es del tipo Cheque, que es el que dispara
     * `ChequeHelper::crear_cheque()` en `PaymentMethodHelper::attach_payment_methods()`.
     *
     * 🔴 Por el SLUG del tipo y nunca por el id: `current_acount_payment_methods` es una tabla
     * GLOBAL sin `user_id`, pero los ids no están garantizados entre instalaciones (el catálogo se
     * siembra y se puede editar desde ABM). El slug es lo único estable, y es lo que mira el
     * backend.
     *
     * @param  \App\Models\CurrentAcountPaymentMethod|null  $metodo  Con `type` cargado.
     * @return bool
     */
    static function es_cheque($metodo) {

        return !is_null($metodo)
            && !is_null($metodo->type)
            && (string) $metodo->type->slug === self::SLUG_CHEQUE;
    }

    /**
     * Métodos de pago para la IA: `[{id, nombre, se_puede_usar, motivo?}]`.
     *
     * @param  \Illuminate\Support\Collection  $metodos
     * @return array
     */
    static function opciones_de_metodos($metodos) {

        $opciones = [];

        foreach ($metodos as $metodo) {

            $motivo = self::motivo_no_usable($metodo);

            $opcion = [
                'id'            => (int) $metodo->id,
                'nombre'        => (string) $metodo->name,
                'se_puede_usar' => is_null($motivo),
            ];

            if (!is_null($motivo)) {

                $opcion['motivo'] = $motivo;
            }

            $opciones[] = $opcion;
        }

        return $opciones;
    }

    /**
     * Subcategorías de gasto (`expense_concepts`) del dueño que coinciden con la búsqueda por el
     * nombre de la subcategoría o de su categoría, tope TOPE. En pantalla se llaman "Sub categoría"
     * (misión gastos-subcategoria-label): el asistente nunca dice "concepto".
     *
     * @param  int  $owner_id
     * @param  string  $busqueda  Vacío trae las primeras por nombre.
     * @return array  [{id, subcategoria, categoria}]
     */
    static function subcategorias_de_gasto($owner_id, $busqueda) {

        $busqueda = trim((string) $busqueda);

        $query = ExpenseConcept::where('user_id', $owner_id)->with('expense_category');

        if ($busqueda !== '') {

            // Los comodines del LIKE se escapan: "50%" tiene que buscar "50%", no todo lo que empieza con 50.
            $escapado = addcslashes($busqueda, '%_\\');

            $query->where(function ($sub) use ($escapado) {

                $sub->where('name', 'LIKE', '%'.$escapado.'%')
                    ->orWhereHas('expense_category', function ($categoria) use ($escapado) {
                        $categoria->where('name', 'LIKE', '%'.$escapado.'%');
                    });
            });
        }

        $resultado = [];

        foreach ($query->orderBy('name')->limit(self::TOPE)->get() as $concepto) {

            $resultado[] = [
                'id'           => (int) $concepto->id,
                'subcategoria' => (string) $concepto->name,
                'categoria'    => is_null($concepto->expense_category) ? null : (string) $concepto->expense_category->name,
            ];
        }

        return $resultado;
    }

    /**
     * "Categoría · Subcategoría" (o solo la subcategoría si no tiene categoría).
     *
     * @param  \App\Models\ExpenseConcept  $concepto  Con `expense_category` cargada.
     * @return string
     */
    static function nombre_de_subcategoria($concepto) {

        if (is_null($concepto->expense_category)) {

            return (string) $concepto->name;
        }

        return $concepto->expense_category->name.' · '.$concepto->name;
    }

    /**
     * Nombres de las unidades de repetición cargadas en la base ('dia', 'semana', 'mes', 'año').
     * Vacío en un cliente viejo sin UnidadFrecuenciaSeeder corrido.
     *
     * @return array<int,string>
     */
    static function unidades_de_repeticion() {

        $nombres = [];

        foreach (UnidadFrecuencia::whereIn('slug', array_keys(self::UNIDADES))->orderBy('id')->get() as $unidad) {

            $nombres[] = self::UNIDADES[$unidad->slug];
        }

        return $nombres;
    }

    /**
     * La UnidadFrecuencia de un nombre dicho por la IA ("mes", "meses", "día", "año"...), o null.
     *
     * @param  string  $nombre
     * @return \App\Models\UnidadFrecuencia|null
     */
    static function unidad_por_nombre($nombre) {

        $normalizado = strtr(mb_strtolower(trim((string) $nombre)), ['í' => 'i', 'ñ' => 'n']);

        $slugs = [
            'dia'     => 'day',
            'dias'    => 'day',
            'semana'  => 'week',
            'semanas' => 'week',
            'mes'     => 'month',
            'meses'   => 'month',
            'ano'     => 'year',
            'anos'    => 'year',
            'anio'    => 'year',
            'anios'   => 'year',
        ];

        if (!isset($slugs[$normalizado])) {

            return null;
        }

        return UnidadFrecuencia::where('slug', $slugs[$normalizado])->first();
    }

    /**
     * Sucursal de la pantalla para esta persona: su `address_id`, o null ("todas las sucursales").
     *
     * @param  \App\Models\User|null  $persona
     * @return int|null
     */
    static function address_id_de_la_persona($persona) {

        if (is_null($persona) || empty($persona->address_id)) {

            return null;
        }

        return (int) $persona->address_id;
    }

    /**
     * `==` de JavaScript entre dos valores que son número o null: null solo es igual a null (y NO a
     * 0, que en PHP `null == 0` daría true), y dos números se comparan por valor.
     *
     * @param  mixed  $a
     * @param  mixed  $b
     * @return bool
     */
    protected static function igual_suelto($a, $b) {

        if (is_null($a) || is_null($b)) {

            return is_null($a) && is_null($b);
        }

        return (float) $a == (float) $b;
    }
}
