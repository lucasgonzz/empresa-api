<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Buyer;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lógica de negocio para vincular un comprador de la tienda (`Buyer`) con un cliente del sistema
 * (`Client`) desde la tabla de Pedidos (misión vincular-comprador-desde-pedidos, 24/9/2026).
 *
 * El vínculo es `buyers.comercio_city_client_id` -> `clients.id`, sin foreign key. Del vínculo
 * depende lo que el comprador hace con el dueño: si NO está vinculado, `CreateSaleOrderHelper::
 * get_client_id()` deja la venta de su pedido con `client_id` null y sin cuenta corriente, y en la
 * tienda no ve sus precios ni su cuenta corriente.
 *
 * Hasta esta misión el vínculo se escribía solo con `GeneralController::setComercioCityUser`, que
 * busca el cliente por IGUALDAD EXACTA de nombre, toma el primero de varios homónimos y no scopea
 * el comprador por dueño. Ese camino queda como está: este helper es el camino nuevo, que elige
 * el cliente por id y scopea TODO (comprador y clientes) por el dueño de la empresa.
 *
 * Son dos operaciones:
 *
 *  - `clientes_para_vincular()`: las coincidencias de clientes del sistema para un comprador, ya
 *    puntuadas y con el motivo de cada una (mismo email, mismo teléfono, mismo nombre...). Son
 *    PISTAS para que la persona elija: nunca vinculan solas.
 *  - `vincular()`: escribe el vínculo, con transacción y candado sobre el comprador para que dos
 *    personas vinculando a la vez el mismo comprador no se pisen sin darse cuenta.
 *
 * 🔴 Todos los métodos reciben el `$user_id` del DUEÑO (`Controller::userId()`, que devuelve el
 * dueño aunque opere un empleado): el helper no sabe quién está logueado y no debe adivinarlo.
 *
 * 🔴 PHP 7.4: nada de sintaxis ni funciones de PHP 8 (es la restricción dura de empresa-api).
 */
class BuyerHelper
{
    /** Cuántas coincidencias se devuelven si el cliente HTTP no pide otra cantidad. */
    const LIMITE_POR_DEFECTO = 30;

    /** Techo de `limit`: por más que se pida, nunca se devuelven más que esto. */
    const LIMITE_MAXIMO = 50;

    /** Largo máximo del texto de búsqueda (`q`); lo que sobra se corta, no se rechaza. */
    const LARGO_MAXIMO_DE_BUSQUEDA = 100;

    /** Cuántas palabras del texto se usan para armar las consultas (las primeras). */
    const MAXIMO_DE_PALABRAS = 6;

    /** Tope de filas que se leen por consulta, sin importar cuántos clientes tenga el comercio. */
    const FILAS_POR_CONSULTA = 300;

    /** Cuántos dígitos FINALES de un teléfono se comparan (sirve igual con o sin código de área). */
    const DIGITOS_DEL_TELEFONO = 8;

    /** Largo mínimo de una palabra para contar como "parecida" (descarta "de", "la", "el"...). */
    const LARGO_MINIMO_DE_PALABRA = 3;

    /** Puntaje de cada motivo. Se suman entre sí. */
    const PUNTOS_EMAIL_IGUAL = 100;
    const PUNTOS_NOMBRE_IGUAL = 90;
    const PUNTOS_TELEFONO_IGUAL = 70;

    /** `nombre_parecido`: puntos por cada palabra compartida, con tope para que no le gane al resto. */
    const PUNTOS_POR_PALABRA_PARECIDA = 10;
    const TOPE_DE_PUNTOS_POR_NOMBRE_PARECIDO = 40;

    /** Mensajes del contrato con la SPA (§3 del plan de la misión). */
    const MENSAJE_COMPRADOR_NO_ENCONTRADO = 'Comprador no encontrado.';
    const MENSAJE_FALTA_CLIENTE = 'Elegí un cliente para vincular.';
    const MENSAJE_CLIENTE_INEXISTENTE = 'El cliente elegido no existe.';

    // -------------------------------------------------------------------------------------------
    //  Comprador
    // -------------------------------------------------------------------------------------------

    /**
     * Devuelve el comprador SI es de este comercio, o null.
     *
     * Un id ajeno y un id que no existe dan lo mismo (null): no se le dice a nadie que ese
     * comprador existe en otro comercio. Un id que no es un entero positivo también es null: MySQL
     * compara `id = '12abc'` como 12 y devolvería el comprador 12.
     *
     * @param  int|string  $buyer_id  Id que llegó por la URL.
     * @param  int    $user_id   Dueño de la empresa.
     * @return \App\Models\Buyer|null
     */
    public static function comprador_del_comercio($buyer_id, $user_id)
    {
        if (!self::es_id($buyer_id)) {
            return null;
        }

        return Buyer::where('id', $buyer_id)
                    ->where('user_id', $user_id)
                    ->first();
    }

    /**
     * Nombre completo del comprador, tal como lo ve el dueño.
     *
     * La tienda guarda `name` y `surname` SEPARADOS (los muestra como "name surname"), pero las
     * semillas y los compradores viejos traen el apellido ya metido en `name`. Por eso el apellido
     * se suma SOLO si no está ya dentro del nombre: "Lucas" + "Gonzalez" da "Lucas Gonzalez", y
     * "Lucas gonzalez" + "Gonzalez" da "Lucas gonzalez" (sin repetirlo, que rompería el
     * `nombre_igual` contra un cliente "Lucas González").
     *
     * "Ya está" quiere decir que TODAS las palabras normalizadas del apellido (sin tildes ni
     * mayúsculas) figuran entre las del nombre. Un apellido sin ninguna palabra útil (un guion, por
     * ejemplo) tampoco se suma.
     *
     * La SPA arma el nombre completo con la misma regla: si se cambia acá, se cambia allá.
     *
     * @param  \App\Models\Buyer  $comprador
     * @return string
     */
    public static function nombre_completo_del_comprador($comprador)
    {
        $nombre = trim((string) $comprador->name);
        $apellido = trim((string) $comprador->surname);

        if ($apellido === '') {
            return $nombre;
        }

        $palabras_del_apellido = self::palabras($apellido);

        if (count($palabras_del_apellido) === 0) {
            return $nombre;
        }

        $faltan = array_diff($palabras_del_apellido, self::palabras($nombre));

        if (count($faltan) === 0) {
            return $nombre;
        }

        return trim($nombre.' '.$apellido);
    }

    // -------------------------------------------------------------------------------------------
    //  Coincidencias
    // -------------------------------------------------------------------------------------------

    /**
     * Coincidencias de clientes del sistema para un comprador, con su puntaje y sus motivos.
     *
     * Dos modos, según haya o no texto de búsqueda:
     *
     *  - `sugerencias` (sin `q`): se buscan clientes a partir de los DATOS DEL COMPRADOR (su
     *    nombre completo, su email y su teléfono).
     *  - `busqueda` (con `q`): cada palabra de `q` tiene que aparecer en el nombre, la razón
     *    social, el email, el teléfono, el CUIT o el DNI del cliente.
     *
     * En los dos modos los motivos y el puntaje se calculan contra el comprador, no contra `q`: en
     * modo búsqueda un cliente puede venir sin ningún motivo (`motivos` vacío, `score` 0) y va
     * ordenado por nombre.
     *
     * @param  \App\Models\Buyer  $comprador  El comprador ya resuelto (ver `comprador_del_comercio()`).
     * @param  int                $user_id    Dueño de la empresa.
     * @param  string|array|null  $q          Texto de búsqueda libre (null o vacío = sugerencias).
     * @param  int|string|null    $limit      Cuántas coincidencias como máximo (1 a 50, por defecto 30).
     * @return array  `['buyer' => ..., 'texto' => ..., 'modo' => ..., 'models' => [...]]`
     */
    public static function clientes_para_vincular($comprador, $user_id, $q = null, $limit = null)
    {
        $limit = self::limite($limit);

        $q = self::limpiar_texto_de_busqueda($q);

        $modo = ($q === '') ? 'sugerencias' : 'busqueda';

        // El texto efectivo del que salen las palabras: el nombre completo del comprador o `q`.
        $texto = ($modo === 'sugerencias') ? self::nombre_completo_del_comprador($comprador) : $q;

        $datos_del_comprador = self::datos_del_comprador($comprador);

        if ($modo === 'sugerencias') {
            $candidatos = self::candidatos_de_sugerencias($user_id, $texto, $datos_del_comprador);
        } else {
            $candidatos = self::candidatos_de_busqueda($user_id, $texto);
        }

        $evaluados = [];

        foreach ($candidatos as $cliente) {
            $evaluados[] = self::evaluar($datos_del_comprador, $cliente);
        }

        // Puntaje de mayor a menor y, a igual puntaje, por nombre. El nombre se compara
        // NORMALIZADO (sin tildes ni mayúsculas): con la comparación de bytes una "Álvarez" quedaría
        // después de una "Zapata". El id es el último desempate porque `usort` no es estable en 7.4.
        usort($evaluados, function ($a, $b) {

            if ($a['score'] !== $b['score']) {
                return $b['score'] - $a['score'];
            }

            $por_nombre = strcmp($a['orden_por_nombre'], $b['orden_por_nombre']);

            if ($por_nombre !== 0) {
                return $por_nombre;
            }

            return $a['id'] - $b['id'];
        });

        $evaluados = array_slice($evaluados, 0, $limit);

        // Una sola consulta para todos los que quedaron, nunca una por cliente.
        $ids = [];

        foreach ($evaluados as $evaluado) {
            $ids[] = $evaluado['id'];
        }

        $con_usuario_en_tienda = self::ids_con_usuario_en_tienda($ids, $user_id, $comprador->id);

        $models = [];

        foreach ($evaluados as $evaluado) {

            $models[] = [
                'id'                      => $evaluado['id'],
                'num'                     => $evaluado['num'],
                'name'                    => $evaluado['name'],
                'razon_social'            => $evaluado['razon_social'],
                'email'                   => $evaluado['email'],
                'phone'                   => $evaluado['phone'],
                'address'                 => $evaluado['address'],
                'cuit'                    => $evaluado['cuit'],
                'dni'                     => $evaluado['dni'],
                'motivos'                 => $evaluado['motivos'],
                'score'                   => $evaluado['score'],
                'tiene_usuario_en_tienda' => isset($con_usuario_en_tienda[$evaluado['id']]),
            ];
        }

        return [
            'buyer'  => [
                'id'                      => (int) $comprador->id,
                'name'                    => $comprador->name,
                'surname'                 => $comprador->surname,
                'email'                   => $comprador->email,
                'phone'                   => $comprador->phone,
                'comercio_city_client_id' => is_null($comprador->comercio_city_client_id) ? null : (int) $comprador->comercio_city_client_id,
            ],
            'texto'  => $texto,
            'modo'   => $modo,
            'models' => $models,
        ];
    }

    /**
     * Candidatos del modo `sugerencias`, en DOS capas para que una palabra muy común no expulse a
     * los buenos.
     *
     * Con un solo `LIKE` por "cualquier palabra" y el tope de 300 filas, un comprador llamado "Juan
     * Perez" en un comercio con 2.000 "Juan" leía 300 "Juan" cualesquiera y podía dejar afuera al
     * único "Juan Perez". Por eso:
     *
     *  1. Fuertes: mismo email, o teléfono coincidente, o TODAS las palabras del nombre dentro del
     *     `name` del cliente.
     *  2. Débiles: CUALQUIER palabra del nombre dentro del `name` (menos las que ya entraron como
     *     fuertes), primero las que comparten más palabras.
     *
     * Se juntan sin duplicar y se puntúan después, en PHP.
     *
     * @param  int    $user_id  Dueño de la empresa.
     * @param  string $texto    Nombre completo del comprador.
     * @param  array  $datos    Salida de `datos_del_comprador()`.
     * @return array<int, \App\Models\Client>
     */
    protected static function candidatos_de_sugerencias($user_id, $texto, $datos)
    {
        $palabras = self::palabras_para_buscar($texto, false);
        $email = $datos['email'];
        $ultimos_digitos = $datos['ultimos_digitos'];

        // Sin email, sin teléfono y sin una palabra útil no hay con qué buscar. Sin este corte, el
        // grupo `where` quedaría vacío y Laravel lo descarta: la consulta traería CUALQUIER cliente.
        if ($email === '' && $ultimos_digitos === '' && count($palabras) === 0) {
            return [];
        }

        // --- Capa 1: fuertes ---------------------------------------------------------------------

        $fuertes = self::consulta_de_clientes($user_id)
            ->where(function ($condiciones) use ($email, $ultimos_digitos, $palabras) {

                if ($email !== '') {
                    $condiciones->orWhereRaw('LOWER(TRIM(clients.email)) = ?', [$email]);
                }

                if ($ultimos_digitos !== '') {
                    $condiciones->orWhereRaw(self::sql_solo_digitos('clients.phone').' LIKE ?', ['%'.$ultimos_digitos]);
                }

                if (count($palabras) > 0) {
                    $condiciones->orWhere(function ($todas) use ($palabras) {

                        foreach ($palabras as $palabra) {
                            $todas->where('clients.name', 'LIKE', '%'.$palabra.'%');
                        }
                    });
                }
            })
            ->orderBy('clients.name')
            ->orderBy('clients.id')
            ->limit(self::FILAS_POR_CONSULTA)
            ->get()
            ->all();

        if (count($palabras) === 0) {
            return $fuertes;
        }

        // --- Capa 2: débiles ---------------------------------------------------------------------

        $ids_de_fuertes = [];

        foreach ($fuertes as $fuerte) {
            $ids_de_fuertes[] = $fuerte->id;
        }

        $debiles = self::consulta_de_clientes($user_id)
            ->where(function ($condiciones) use ($palabras) {

                foreach ($palabras as $palabra) {
                    $condiciones->orWhere('clients.name', 'LIKE', '%'.$palabra.'%');
                }
            });

        if (count($ids_de_fuertes) > 0) {
            $debiles->whereNotIn('clients.id', $ids_de_fuertes);
        }

        // Con el tope de filas, que entren primero los que comparten MÁS palabras: cada
        // `(name LIKE ?)` vale 1 o 0 y la suma es la cantidad de palabras que coinciden.
        $sumandos = [];
        $como_patrones = [];

        foreach ($palabras as $palabra) {
            $sumandos[] = '(clients.name LIKE ?)';
            $como_patrones[] = '%'.$palabra.'%';
        }

        $debiles = $debiles
            ->orderByRaw('('.implode(' + ', $sumandos).') DESC', $como_patrones)
            ->orderBy('clients.name')
            ->orderBy('clients.id')
            ->limit(self::FILAS_POR_CONSULTA)
            ->get()
            ->all();

        return array_merge($fuertes, $debiles);
    }

    /**
     * Candidatos del modo `busqueda`: cada palabra de `q` tiene que aparecer (`LIKE %palabra%`) en
     * alguna de las columnas de identificación del cliente. AND entre palabras, OR entre columnas.
     *
     * Dos agregados para lo que la persona suele pegar en el buscador:
     *  - una palabra numérica también vale como número de cliente (`num = palabra`);
     *  - una palabra de 4+ dígitos también se busca contra el teléfono, el CUIT y el DNI SIN sus
     *    separadores, así "1122334455" encuentra "11 2233-4455" y "20123456789" encuentra
     *    "20-12345678-9". Con menos de 4 dígitos no: "22" no puede encontrar "2 2".
     *
     * @param  int     $user_id  Dueño de la empresa.
     * @param  string  $texto    El `q` ya limpio.
     * @return array<int, \App\Models\Client>
     */
    protected static function candidatos_de_busqueda($user_id, $texto)
    {
        $palabras = self::palabras_para_buscar($texto, true);

        // Un `q` sin ninguna palabra útil ("%%", "-", una letra suelta) no busca nada: devolver
        // todos los clientes por una tecla de más no le sirve a nadie.
        if (count($palabras) === 0) {
            return [];
        }

        $consulta = self::consulta_de_clientes($user_id);

        foreach ($palabras as $palabra) {

            $consulta->where(function ($condiciones) use ($palabra) {

                $patron = '%'.$palabra.'%';

                foreach (['name', 'razon_social', 'email', 'phone', 'cuit', 'dni'] as $columna) {
                    $condiciones->orWhere('clients.'.$columna, 'LIKE', $patron);
                }

                if (ctype_digit($palabra)) {

                    // 9 dígitos como máximo: más largo no cabe en la columna INT y no puede ser un `num`.
                    if (strlen($palabra) <= 9) {
                        $condiciones->orWhere('clients.num', '=', (int) $palabra);
                    }

                    if (strlen($palabra) >= 4) {

                        foreach (['phone', 'cuit', 'dni'] as $columna) {
                            $condiciones->orWhereRaw(self::sql_solo_digitos('clients.'.$columna).' LIKE ?', [$patron]);
                        }
                    }
                }
            });
        }

        return $consulta
            ->orderBy('clients.name')
            ->orderBy('clients.id')
            ->limit(self::FILAS_POR_CONSULTA)
            ->get()
            ->all();
    }

    /**
     * Base de las consultas de clientes: solo los del dueño, solo las columnas que se muestran.
     * `SoftDeletes` de `Client` ya deja afuera a los borrados.
     *
     * @param  int  $user_id  Dueño de la empresa.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function consulta_de_clientes($user_id)
    {
        return Client::query()
                    ->where('clients.user_id', $user_id)
                    ->select([
                        'clients.id',
                        'clients.num',
                        'clients.name',
                        'clients.razon_social',
                        'clients.email',
                        'clients.phone',
                        'clients.address',
                        'clients.cuit',
                        'clients.dni',
                    ]);
    }

    /**
     * Expresión SQL que deja solo los dígitos de una columna de texto (teléfono, CUIT, DNI).
     *
     * 🔴 `REPLACE` anidado y NO `REGEXP_REPLACE`: el shared hosting de los clientes es MariaDB, y
     * ahí `REGEXP_REPLACE` no existe con la misma sintaxis (ni en las versiones viejas). Solo
     * saca los separadores que se usan de verdad al cargar un teléfono o un CUIT; cualquier otro
     * carácter queda y ese registro simplemente no matchea por acá.
     *
     * `$columna` sale siempre de una lista fija de este archivo, nunca del usuario.
     *
     * @param  string  $columna  Columna ya calificada (`clients.phone`).
     * @return string
     */
    protected static function sql_solo_digitos($columna)
    {
        $expresion = $columna;

        foreach ([' ', '-', '+', '(', ')', '.', '/'] as $separador) {
            $expresion = "REPLACE(".$expresion.", '".$separador."', '')";
        }

        return $expresion;
    }

    /**
     * Motivos y puntaje de UN cliente contra el comprador.
     *
     *  - `email_igual`: los dos emails no vacíos y iguales, sin importar mayúsculas ni espacios de
     *    borde. 100 puntos.
     *  - `nombre_igual`: el conjunto de palabras normalizadas del comprador es idéntico al del
     *    cliente, sin importar el orden ("gonzalez lucas" es "Lucas González"). 90 puntos.
     *  - `telefono_igual`: los dos teléfonos tienen 8 dígitos o más y sus últimos 8 coinciden.
     *    70 puntos.
     *  - `nombre_parecido`: no es `nombre_igual` y comparten al menos una palabra de 3+ letras. 10
     *    puntos por palabra compartida, con tope de 40.
     *
     * Los motivos salen en ese orden de fuerza y sus puntos se suman.
     *
     * @param  array               $datos    Salida de `datos_del_comprador()`.
     * @param  \App\Models\Client  $cliente
     * @return array  Los datos del cliente más `motivos`, `score` y `orden_por_nombre` (interno).
     */
    protected static function evaluar($datos, $cliente)
    {
        $motivos = [];
        $puntos = 0;

        // Email.
        $email_del_cliente = mb_strtolower(trim((string) $cliente->email));

        if ($datos['email'] !== '' && $email_del_cliente === $datos['email']) {
            $motivos[] = 'email_igual';
            $puntos += self::PUNTOS_EMAIL_IGUAL;
        }

        // Nombre. Los dos conjuntos son de palabras únicas: ordenados, son iguales si y solo si
        // tienen exactamente las mismas palabras. Un conjunto vacío nunca es "igual" a otro.
        $palabras_del_cliente = self::palabras($cliente->name);

        $nombre_igual = false;

        if (count($datos['palabras']) > 0 && count($palabras_del_cliente) > 0) {

            $del_comprador = $datos['palabras'];
            $del_cliente = $palabras_del_cliente;

            sort($del_comprador);
            sort($del_cliente);

            $nombre_igual = ($del_comprador === $del_cliente);
        }

        if ($nombre_igual) {
            $motivos[] = 'nombre_igual';
            $puntos += self::PUNTOS_NOMBRE_IGUAL;
        }

        // Teléfono.
        if ($datos['ultimos_digitos'] !== '') {

            $digitos_del_cliente = preg_replace('/\D+/', '', (string) $cliente->phone);

            if (strlen($digitos_del_cliente) >= self::DIGITOS_DEL_TELEFONO
                && substr($digitos_del_cliente, -self::DIGITOS_DEL_TELEFONO) === $datos['ultimos_digitos']) {

                $motivos[] = 'telefono_igual';
                $puntos += self::PUNTOS_TELEFONO_IGUAL;
            }
        }

        // Nombre parecido: solo si no es igual (el igual ya suma más y las palabras compartidas
        // serían todas).
        if (!$nombre_igual) {

            $compartidas = count(array_intersect($datos['palabras_largas'], self::solo_palabras_largas($palabras_del_cliente)));

            if ($compartidas > 0) {
                $motivos[] = 'nombre_parecido';
                $puntos += min(self::TOPE_DE_PUNTOS_POR_NOMBRE_PARECIDO, $compartidas * self::PUNTOS_POR_PALABRA_PARECIDA);
            }
        }

        return [
            'id'               => (int) $cliente->id,
            'num'              => is_null($cliente->num) ? null : (int) $cliente->num,
            'name'             => $cliente->name,
            'razon_social'     => $cliente->razon_social,
            'email'            => $cliente->email,
            'phone'            => $cliente->phone,
            'address'          => $cliente->address,
            'cuit'             => $cliente->cuit,
            'dni'              => $cliente->dni,
            'motivos'          => $motivos,
            'score'            => $puntos,
            'orden_por_nombre' => self::normalizar($cliente->name),
        ];
    }

    /**
     * Lo que se necesita del comprador para puntuar, calculado una sola vez y no por cliente.
     *
     * @param  \App\Models\Buyer  $comprador
     * @return array  `email` (minúsculas, sin espacios de borde), `ultimos_digitos` ('' si el
     *                teléfono tiene menos de 8 dígitos), `palabras` (todas las del nombre
     *                completo, únicas) y `palabras_largas` (las de 3+ caracteres).
     */
    protected static function datos_del_comprador($comprador)
    {
        $palabras = self::palabras(self::nombre_completo_del_comprador($comprador));

        $digitos = preg_replace('/\D+/', '', (string) $comprador->phone);

        return [
            'email'           => mb_strtolower(trim((string) $comprador->email)),
            'ultimos_digitos' => (strlen($digitos) >= self::DIGITOS_DEL_TELEFONO) ? substr($digitos, -self::DIGITOS_DEL_TELEFONO) : '',
            'palabras'        => $palabras,
            'palabras_largas' => self::solo_palabras_largas($palabras),
        ];
    }

    /**
     * Ids de los clientes (entre los dados) a los que YA apunta otro comprador del mismo comercio.
     * Es la pista "Ya tiene usuario en la tienda": un cliente puede quedar con más de un comprador
     * (no hay unique), pero conviene que la persona lo sepa antes de elegirlo.
     *
     * El comprador que se está vinculando no cuenta: su propio vínculo no es "otro".
     *
     * @param  array  $client_ids     Ids de clientes.
     * @param  int    $user_id        Dueño de la empresa.
     * @param  int    $comprador_id   El comprador que se está mirando.
     * @return array<int, int>  Mapa `client_id => posición`, para preguntar con `isset()`.
     */
    protected static function ids_con_usuario_en_tienda($client_ids, $user_id, $comprador_id)
    {
        if (count($client_ids) === 0) {
            return [];
        }

        $vinculados = Buyer::query()
                        ->where('user_id', $user_id)
                        ->where('id', '<>', $comprador_id)
                        ->whereIn('comercio_city_client_id', $client_ids)
                        ->pluck('comercio_city_client_id')
                        ->all();

        return array_flip(array_map('intval', $vinculados));
    }

    // -------------------------------------------------------------------------------------------
    //  Vincular
    // -------------------------------------------------------------------------------------------

    /**
     * Vincula un comprador con un cliente del sistema.
     *
     * Devuelve el código HTTP y el cuerpo para que el controlador no tenga lógica:
     *
     *  - 200 `{model}`: vinculado. Idempotente: si ya estaba vinculado a ESE cliente, también 200
     *    (y no escribe nada).
     *  - 404: el comprador no existe o es de otro comercio.
     *  - 422: falta `client_id`, o el cliente no existe, es de otro comercio o está borrado.
     *  - 409: el comprador ya está vinculado a OTRO cliente vivo y no vino `reemplazar`.
     *
     * 🔴 Un vínculo colgante (el cliente al que apuntaba fue borrado) NO cuenta como vínculo: no
     * hace falta `reemplazar`. El sistema ya lo trata como "sin cliente" —
     * `CreateSaleOrderHelper::get_client_id()` deja la venta con `client_id` null— y la SPA lo
     * muestra como "Sin vincular"; pedirle una confirmación para pisar algo que no existe sería
     * absurdo. "Vivo" quiere decir que existe, no está borrado y es de ESTE comercio.
     *
     * Va todo dentro de una transacción con `lockForUpdate` sobre el comprador: dos personas
     * vinculando a la vez el mismo comprador se serializan, y la segunda ve el vínculo de la
     * primera (y recibe el 409) en vez de pisarlo sin enterarse.
     *
     * No hay "desvincular": está fuera del alcance de la misión.
     *
     * @param  int|string  $buyer_id  Id del comprador (de la URL).
     * @param  int|string|float|array|null  $client_id  Id del cliente elegido (del cuerpo).
     * @param  bool|int|string|null  $reemplazar  Si es true, pisa un vínculo vivo a otro cliente.
     * @param  int  $user_id  Dueño de la empresa.
     * @return array  `['status' => int, 'body' => array]`
     */
    public static function vincular($buyer_id, $client_id, $reemplazar, $user_id)
    {
        if (!self::es_id($buyer_id)) {
            return self::respuesta(404, ['message' => self::MENSAJE_COMPRADOR_NO_ENCONTRADO]);
        }

        $reemplazar = filter_var($reemplazar, FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($buyer_id, $client_id, $reemplazar, $user_id) {

            $comprador = Buyer::where('id', $buyer_id)
                            ->where('user_id', $user_id)
                            ->lockForUpdate()
                            ->first();

            // El 404 va primero: con un comprador ajeno no se revela nada por los otros errores.
            if (is_null($comprador)) {
                return self::respuesta(404, ['message' => self::MENSAJE_COMPRADOR_NO_ENCONTRADO]);
            }

            if (is_null($client_id) || $client_id === '') {
                return self::respuesta(422, ['message' => self::MENSAJE_FALTA_CLIENTE]);
            }

            if (!self::es_id($client_id)) {
                return self::respuesta(422, ['message' => self::MENSAJE_CLIENTE_INEXISTENTE]);
            }

            // `SoftDeletes` de `Client` deja afuera al borrado; el `user_id`, al de otro comercio.
            $cliente = Client::where('id', $client_id)
                            ->where('user_id', $user_id)
                            ->first();

            if (is_null($cliente)) {
                return self::respuesta(422, ['message' => self::MENSAJE_CLIENTE_INEXISTENTE]);
            }

            $cliente_actual = self::cliente_vinculado_vivo($comprador, $user_id);

            if (!is_null($cliente_actual)
                && (int) $cliente_actual->id !== (int) $cliente->id
                && !$reemplazar) {

                return self::respuesta(409, [
                    'message'        => 'Este comprador ya está vinculado a «'.$cliente_actual->name.'».',
                    'cliente_actual' => [
                        'id'   => (int) $cliente_actual->id,
                        'name' => $cliente_actual->name,
                    ],
                ]);
            }

            // Idempotente: si ya apunta a este cliente no hay nada que escribir.
            if ((int) $comprador->comercio_city_client_id !== (int) $cliente->id) {
                $comprador->comercio_city_client_id = $cliente->id;
                $comprador->save();
            }

            // 🔴 Acá NO va `fullModel('Buyer')`: su `withAll()` carga `messages` con `article.images`
            // y en un comercio con historia larga (Fenix: 87.928 mensajes) es lo que tumbó el VPS
            // el 9/9/2026. Para que la SPA actualice el vínculo alcanzan estas dos relaciones.
            $comprador->load('addresses', 'comercio_city_client');

            return self::respuesta(200, ['model' => $comprador]);
        });
    }

    /**
     * El cliente al que el comprador está vinculado HOY, o null si no lo está.
     *
     * Null también cuando el vínculo cuelga de un cliente borrado o de un cliente de otro
     * comercio (un dato incoherente que no debería existir): en ningún caso corresponde pedir
     * confirmación para pisarlo, ni mostrarle a un comercio el nombre de un cliente ajeno.
     *
     * @param  \App\Models\Buyer  $comprador
     * @param  int                $user_id  Dueño de la empresa.
     * @return \App\Models\Client|null
     */
    protected static function cliente_vinculado_vivo($comprador, $user_id)
    {
        if (is_null($comprador->comercio_city_client_id)) {
            return null;
        }

        return Client::where('id', $comprador->comercio_city_client_id)
                    ->where('user_id', $user_id)
                    ->first();
    }

    /**
     * Arma el resultado de `vincular()`.
     *
     * @param  int    $status
     * @param  array  $body
     * @return array
     */
    protected static function respuesta($status, $body)
    {
        return ['status' => $status, 'body' => $body];
    }

    // -------------------------------------------------------------------------------------------
    //  Texto, palabras y parámetros
    // -------------------------------------------------------------------------------------------

    /**
     * Normaliza un texto para compararlo: sin tildes, en minúsculas, y todo lo que no sea letra o
     * número pasa a ser un espacio. "Peña,  José-Luis" queda "pena jose luis".
     *
     * @param  string|null  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $texto = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texto));
    }

    /**
     * Las palabras de un texto ya normalizado, sin repetir y en el orden en que aparecen.
     * No filtra por largo: el conjunto completo es lo que se compara para `nombre_igual`.
     *
     * @param  string|null  $texto
     * @return array<int, string>
     */
    public static function palabras($texto)
    {
        $normalizado = self::normalizar($texto);

        if ($normalizado === '') {
            return [];
        }

        return array_values(array_unique(explode(' ', $normalizado)));
    }

    /**
     * Las palabras con las que se arma una consulta.
     *
     * En modo `sugerencias` se descartan las de menos de 3 caracteres ("de", "la", "el": están en
     * medio mundo de nombres y solo agregan ruido). En modo `busqueda` la persona las escribió a
     * propósito, así que se aceptan: de 1 carácter si son dígitos (el número de un cliente, un
     * pedazo de teléfono) y de 2 o más si son letras. En los dos modos, máximo 6 palabras.
     *
     * @param  string  $texto
     * @param  bool    $modo_busqueda
     * @return array<int, string>
     */
    protected static function palabras_para_buscar($texto, $modo_busqueda)
    {
        $elegidas = [];

        foreach (self::palabras($texto) as $palabra) {

            $largo = strlen($palabra);

            if ($modo_busqueda) {
                $sirve = ctype_digit($palabra) ? ($largo >= 1) : ($largo >= 2);
            } else {
                $sirve = ($largo >= self::LARGO_MINIMO_DE_PALABRA);
            }

            if ($sirve) {
                $elegidas[] = $palabra;
            }

            if (count($elegidas) >= self::MAXIMO_DE_PALABRAS) {
                break;
            }
        }

        return $elegidas;
    }

    /**
     * De una lista de palabras, las que tienen 3 caracteres o más.
     *
     * @param  array<int, string>  $palabras
     * @return array<int, string>
     */
    protected static function solo_palabras_largas($palabras)
    {
        $largas = [];

        foreach ($palabras as $palabra) {

            if (strlen($palabra) >= self::LARGO_MINIMO_DE_PALABRA) {
                $largas[] = $palabra;
            }
        }

        return $largas;
    }

    /**
     * Deja el `q` listo: solo texto, sin espacios de borde y con el largo máximo. Cualquier cosa
     * que no sea un string (`?q[]=x`) cuenta como "sin `q`".
     *
     * @param  string|array|null  $q
     * @return string
     */
    protected static function limpiar_texto_de_busqueda($q)
    {
        if (!is_string($q)) {
            return '';
        }

        return trim(mb_substr(trim($q), 0, self::LARGO_MAXIMO_DE_BUSQUEDA));
    }

    /**
     * `limit` acotado: un entero de 1 a 50, y 30 si no vino o no es un número.
     *
     * @param  int|string|array|null  $valor
     * @return int
     */
    protected static function limite($valor)
    {
        if (!is_string($valor) && !is_int($valor)) {
            return self::LIMITE_POR_DEFECTO;
        }

        if (!is_numeric($valor)) {
            return self::LIMITE_POR_DEFECTO;
        }

        return max(1, min(self::LIMITE_MAXIMO, (int) $valor));
    }

    /**
     * ¿Es un id válido? Un entero positivo, o un string que sea solo dígitos (así llegan los ids de
     * la URL y de un formulario). Todo lo demás —'12abc', 12.5, [], '', 0— no es un id.
     *
     * @param  int|string|float|array|null  $valor
     * @return bool
     */
    protected static function es_id($valor)
    {
        if (is_int($valor)) {
            return $valor > 0;
        }

        return is_string($valor) && ctype_digit($valor) && (int) $valor > 0;
    }
}
