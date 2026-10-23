<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra UN comercio a "siempre con sucursal" (misión sucursal-siempre, 23/10/2026, D13).
 *
 * ─── Para qué ─────────────────────────────────────────────────────────────────────────────────
 *
 * Todo negocio pasa a trabajar con al menos una sucursal y con el stock de cada artículo en
 * `address_article` (el global es la suma). Los clientes que hoy no tienen sucursales, y los que las
 * tienen pero con artículos que todavía llevan stock global (el "estado mixto" de 2R), se migran con
 * este comando, de a uno, con respaldo. No es código que corra solo en el release: un seeder tocaría a
 * toda la flota a la vez (lo acordado con Lucas).
 *
 *     php artisan sucursales:migrar-a-principal 38 --ver        # solo lee (por defecto)
 *     php artisan sucursales:migrar-a-principal 38 --aplicar    # escribe, con respaldo y reversión
 *
 * ─── Qué hace, en orden ───────────────────────────────────────────────────────────────────────
 *
 *  a. Filas fantasma (filas de `address_article` / `address_article_variant` de sucursales que ya no
 *     existen): si hay, FRENA (exit 1) y pide correr antes `stock:sanear-sucursales-borradas`. Un
 *     fantasma suma en `articles.stock` y no lo ve nadie: migrar encima dejaría el global distinto de
 *     la suma de las sucursales que se ven.
 *  b. La sucursal principal: si el dueño no tiene sucursales, crea una (`street` = `--nombre`,
 *     "Principal" por defecto, D9; `street_number` = `users.address_company` o, si está vacío, el
 *     `domicilio_comercial` de su primer punto de venta de ARCA, D8) y la marca principal. Si tiene,
 *     asegura que haya UNA marcada (`Address::asegurar_principal()`, D2). Nunca crea si ya hay.
 *  c. Solo si la creó (el comercio pasó de 0 a 1 sucursal): `users.address_id` = la principal para el
 *     dueño y los empleados que lo tengan vacío o apuntando a una sucursal que no existe. Si ya tenía
 *     sucursales, los empleados no se tocan (cada uno ya eligió la suya).
 *  d. Cada artículo del dueño SIN fila viva, incluida la papelera:
 *     - stock ≠ 0 → un movimiento "Creacion de deposito" a la principal por el stock ENTERO, por el
 *       motor (`StockMovementController::crear()`), con la observación OBSERVACION y sin avisar a
 *       Tienda Nube ni a Mercado Libre (`sin_sincronizar`: el stock publicado no cambia);
 *     - stock 0 o null → la fila con 0 directa, sin movimiento (y el null pasa a 0);
 *     - en la papelera → la fila directa con su stock, sin movimiento: el motor no ve los artículos de
 *       la papelera (`Article::find`), mismo criterio que la D7 de eliminar-sucursal-con-stock;
 *     - con variantes: lo mismo por cada variante que no reparte (con `article_variant_id`), así el
 *       recálculo desde las variantes (`setArticleStockFromAddresses`) no pisa nada.
 *     Una fila en el domicilio de un COMPRADOR (`buyer_id`, pedidos viejos de la tienda) no es una
 *     sucursal: el artículo cuenta como sin fila, y a la principal va el stock MENOS lo que ya suman sus
 *     filas, para que el global quede idéntico. Los movimientos no tocan `stock_updated_at`
 *     (`sin_stock_updated_at`): el stock no cambia. Ese valor igual queda en el respaldo y en la reversión.
 *  e. Verifica artículo por artículo que `articles.stock` y el de cada variante quedaron IDÉNTICOS a
 *     los de antes (null cuenta como 0). Si alguno difiere, lo informa y revierte ESE artículo: cada
 *     artículo va en su transacción.
 *  f. Idempotente: una segunda corrida no encuentra nada que hacer.
 *  g. Respaldo write-ahead en `storage/app/respaldos/sucursal-principal/<fecha>/`: `respaldo.jsonl`
 *     (las sucursales y los usuarios del comercio antes de tocarlos, y por artículo sus filas, su
 *     stock y el de sus variantes ANTES de tocarlo) y `reversion.sql` (un bloque por artículo, más la
 *     sucursal y los usuarios, que lo devuelve a como estaba). Si el respaldo no se puede abrir, no se
 *     escribe nada.
 *
 * `--ver` (por defecto, y gana si llegan los dos) no escribe nada, ni en la base ni en archivos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class MigrarASucursalPrincipal extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sucursales:migrar-a-principal
                            {user_id? : Dueño del comercio; por defecto config(\'app.USER_ID\')}
                            {--ver : Solo lee y reporta (por defecto). No escribe nada.}
                            {--aplicar : Escribe: sucursal principal, empleados y una fila por artículo, con respaldo y SQL de reversión.}
                            {--nombre=Principal : Nombre de la sucursal si hay que crearla.}
                            {--salida= : Carpeta base del respaldo (por defecto storage/app/respaldos/sucursal-principal).}';

    /**
     * @var string
     */
    protected $description = 'Migra un comercio a "siempre con sucursal": crea o asegura la sucursal principal y le pasa el stock global de cada artículo sin fila. --ver por defecto; --aplicar con respaldo y SQL de reversión.';

    /** Observación de los movimientos que deja el comando (para encontrarlos en el libro). */
    const OBSERVACION = 'Migración a sucursal principal (sucursales:migrar-a-principal)';

    /** Concepto de los movimientos. */
    const CONCEPTO = 'Creacion de deposito';

    /** Diferencia de stock que se tolera al verificar (la columna guarda 2 decimales). */
    const TOLERANCIA = 0.005;

    /** Carpeta base del respaldo, dentro de storage/app. */
    const CARPETA = 'respaldos/sucursal-principal';

    /** Se imprime en --ver y al terminar --aplicar. */
    const AVISO_HORARIO = 'Correr --aplicar FUERA del horario del negocio: la reversión solo vale inmediatamente después de migrar (si hubo ventas en el medio, las pisa).';

    /** Tamaño de las tandas de lectura de artículos. */
    const TANDA = 500;

    /** @var resource|null */
    private $respaldo = null;

    /** @var resource|null */
    private $sql = null;

    /** @var string|null Carpeta de esta corrida. */
    private $carpeta = null;

    /**
     * @return int
     */
    public function handle()
    {
        $user_id = $this->argument('user_id');

        if (is_null($user_id) || $user_id === '') {
            $user_id = config('app.USER_ID');
        }

        if (!is_numeric($user_id) || (int) $user_id <= 0) {
            $this->error('Falta el dueño: pasalo como argumento (sucursales:migrar-a-principal <user_id>) o configurá USER_ID.');

            return 1;
        }

        $user_id = (int) $user_id;

        $dueno = User::find($user_id);

        if (is_null($dueno)) {
            $this->error('No existe el usuario '.$user_id.'.');

            return 1;
        }

        if (!is_null($dueno->owner_id)) {
            $this->error('El usuario '.$user_id.' es empleado (owner_id '.$dueno->owner_id.'): pasá el id del dueño.');

            return 1;
        }

        $aplicar = (bool) $this->option('aplicar') && !(bool) $this->option('ver');

        $nombre = trim((string) $this->option('nombre'));

        if ($nombre === '') {
            $nombre = 'Principal';
        }

        $this->info('Migración a sucursal principal · dueño '.$user_id.' ('.$dueno->name.') · base '.DB::connection()->getDatabaseName());

        if ($this->option('aplicar') && $this->option('ver')) {
            $this->warn('Llegaron --ver y --aplicar juntos: gana la lectura, no se escribe nada.');
        }

        $this->line($aplicar ? 'Modo: APLICAR (escribe, con respaldo y SQL de reversión).' : 'Modo: VER (solo lee: no escribe en la base ni en archivos).');

        // a. Filas fantasma: se frena en los dos modos.
        $fantasmas = FilasFantasmaDeSucursalHelper::ids_de_articulos_afectados($user_id);

        if (count($fantasmas) > 0) {
            $this->error('Hay '.count($fantasmas).' artículo(s) con filas de sucursales que ya no existen (filas fantasma). '
                .'Corré antes: php artisan stock:sanear-sucursales-borradas --user_id='.$user_id.' (primero --ver, después --aplicar). '
                .'No se migra nada hasta que no queden.');

            return 1;
        }

        $medicion = $this->medir($dueno, $nombre);

        $this->reportar($medicion);

        if (!$aplicar) {
            $this->line('');
            $this->info('Modo ver: no se escribió nada. Para migrar, corré el mismo comando con --aplicar.');

            return 0;
        }

        if ($medicion['nada_que_hacer']) {
            $this->info('No hay nada que migrar: el comercio ya tiene su sucursal principal y todos los artículos con su fila.');

            return 0;
        }

        if (count($medicion['articulos']) > 0 && is_null(ConceptoStockMovement::where('name', self::CONCEPTO)->value('id'))) {
            $this->error('No existe el concepto de stock "'.self::CONCEPTO.'" en esta base: no se escribe nada (el libro quedaría con un concepto ajeno).');

            return 1;
        }

        if (!$this->abrir_respaldo($user_id)) {
            return 1;
        }

        try {
            return $this->aplicar($dueno, $medicion, $nombre);
        } finally {
            $this->cerrar_respaldo();
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  MEDICIÓN (solo lectura, la misma en los dos modos)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Lo que haría la migración, sin escribir nada.
     *
     * @param  User    $dueno
     * @param  string  $nombre
     * @return array
     */
    private function medir($dueno, $nombre)
    {
        $sucursales = Address::sucursales_de($dueno->id)->get();

        $principal = count($sucursales) ? Address::principal_de($dueno->id) : null;

        $crear = count($sucursales) === 0;

        $marcadas = Address::sucursales_de($dueno->id)->where('default_address', 1)->count();

        $articulos = $this->articulos_sin_fila($dueno->id);

        $casos = [
            'con_stock'        => 0,
            'en_cero_o_null'   => 0,
            'papelera'         => 0,
            'con_variantes'    => 0,
            'variantes'        => 0,
            'solo_comprador'   => 0,
        ];

        foreach ($articulos as $a) {
            if (!empty($a['solo_comprador'])) {
                $casos['solo_comprador']++;
            }

            if ($a['papelera']) {
                $casos['papelera']++;
            }

            if (count($a['variantes']) > 0) {
                $casos['con_variantes']++;
                $casos['variantes'] += count($a['variantes']);
            } elseif ($a['stock'] != 0.0) {
                $casos['con_stock']++;
            } else {
                $casos['en_cero_o_null']++;
            }
        }

        return [
            'sucursales'       => count($sucursales),
            'crear'            => $crear,
            'principal'        => $principal,
            'marcar'           => !$crear && $marcadas !== 1,
            'domicilio'        => $crear ? $this->domicilio_para_la_principal($dueno) : null,
            'nombre'           => $nombre,
            'empleados'        => $crear ? $this->usuarios_sin_sucursal($dueno->id) : [],
            'articulos'        => $articulos,
            'casos'            => $casos,
            'nada_que_hacer'   => !$crear && $marcadas === 1 && count($articulos) === 0,
        ];
    }

    /**
     * Los artículos del dueño (con la papelera) que no tienen ninguna fila viva, y de los que tienen
     * variantes, las variantes sin fila viva. "Viva" = de una dirección que existe (la misma vara que
     * la relación `addresses` del motor, un INNER JOIN).
     *
     * @param  int  $user_id
     * @return array  [{id, stock, stock_crudo, papelera, variantes: [{id, stock, stock_crudo}]}]
     */
    private function articulos_sin_fila($user_id)
    {
        $resultado = [];

        $ultimo_id = 0;

        while (true) {

            $tanda = DB::table('articles')
                ->where('user_id', $user_id)
                ->where('id', '>', $ultimo_id)
                ->orderBy('id')
                ->limit(self::TANDA)
                ->get(['id', 'stock', 'deleted_at']);

            if (count($tanda) === 0) {
                break;
            }

            $ids = [];

            foreach ($tanda as $fila) {
                $ids[] = (int) $fila->id;
                $ultimo_id = (int) $fila->id;
            }

            // Una fila en el domicilio de un COMPRADOR (buyer_id) no es una sucursal: un artículo cuya
            // única fila es de un comprador cuenta como sin fila (y el informe lo dice aparte).
            $con_fila = DB::table('address_article as aa')
                ->join('addresses as ad', 'ad.id', '=', 'aa.address_id')
                ->whereNull('ad.buyer_id')
                ->whereIn('aa.article_id', $ids)
                ->distinct()
                ->pluck('aa.article_id')
                ->all();

            $con_fila = array_flip(array_map('intval', $con_fila));

            $con_fila_de_comprador = DB::table('address_article as aa')
                ->join('addresses as ad', 'ad.id', '=', 'aa.address_id')
                ->whereNotNull('ad.buyer_id')
                ->whereIn('aa.article_id', $ids)
                ->distinct()
                ->pluck('aa.article_id')
                ->all();

            $con_fila_de_comprador = array_flip(array_map('intval', $con_fila_de_comprador));

            $variantes = DB::table('article_variants')
                ->whereIn('article_id', $ids)
                ->orderBy('id')
                ->get(['id', 'article_id', 'stock']);

            $variantes_por_articulo = [];
            $ids_variantes = [];

            foreach ($variantes as $v) {
                $variantes_por_articulo[(int) $v->article_id][] = $v;
                $ids_variantes[] = (int) $v->id;
            }

            $variantes_con_fila = [];

            if (count($ids_variantes) > 0) {
                $variantes_con_fila = DB::table('address_article_variant as aav')
                    ->join('addresses as ad', 'ad.id', '=', 'aav.address_id')
                    ->whereNull('ad.buyer_id')
                    ->whereIn('aav.article_variant_id', $ids_variantes)
                    ->distinct()
                    ->pluck('aav.article_variant_id')
                    ->all();

                $variantes_con_fila = array_flip(array_map('intval', $variantes_con_fila));
            }

            foreach ($tanda as $fila) {

                $id = (int) $fila->id;

                if (isset($variantes_por_articulo[$id])) {

                    $pendientes = [];

                    foreach ($variantes_por_articulo[$id] as $v) {
                        if (!isset($variantes_con_fila[(int) $v->id])) {
                            $pendientes[] = [
                                'id'          => (int) $v->id,
                                'stock'       => is_null($v->stock) ? 0.0 : (float) $v->stock,
                                'stock_crudo' => $v->stock,
                            ];
                        }
                    }

                    if (count($pendientes) === 0) {
                        continue;
                    }

                    $resultado[] = [
                        'id'          => $id,
                        'stock'       => is_null($fila->stock) ? 0.0 : (float) $fila->stock,
                        'stock_crudo' => $fila->stock,
                        'papelera'    => !is_null($fila->deleted_at),
                        'variantes'   => $pendientes,
                        'solo_comprador' => false,
                    ];

                    continue;
                }

                if (isset($con_fila[$id])) {
                    continue;
                }

                $resultado[] = [
                    'id'          => $id,
                    'stock'       => is_null($fila->stock) ? 0.0 : (float) $fila->stock,
                    'stock_crudo' => $fila->stock,
                    'papelera'    => !is_null($fila->deleted_at),
                    'variantes'   => [],
                    'solo_comprador' => isset($con_fila_de_comprador[$id]),
                ];
            }
        }

        return $resultado;
    }

    /**
     * El domicilio que se le precarga a la principal que se crea (D8): `users.address_company` o, si
     * está vacío, el `domicilio_comercial` del primer punto de venta de ARCA del dueño. null si no hay.
     *
     * @param  User  $dueno
     * @return string|null
     */
    private function domicilio_para_la_principal($dueno)
    {
        $empresa = trim((string) $dueno->address_company);

        if ($empresa !== '') {
            return $empresa;
        }

        $arca = DB::table('afip_information')
            ->where('user_id', $dueno->id)
            ->whereNotNull('domicilio_comercial')
            ->where('domicilio_comercial', '!=', '')
            ->orderBy('id')
            ->value('domicilio_comercial');

        return is_null($arca) ? null : trim((string) $arca);
    }

    /**
     * El dueño y los empleados con `address_id` vacío o apuntando a una sucursal que no existe.
     *
     * @param  int  $user_id
     * @return array  [{id, name, address_id}]
     */
    private function usuarios_sin_sucursal($user_id)
    {
        $filas = DB::table('users as u')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'u.address_id')
            ->where(function ($q) use ($user_id) {
                $q->where('u.id', $user_id)->orWhere('u.owner_id', $user_id);
            })
            ->where(function ($q) {
                $q->whereNull('u.address_id')->orWhere('u.address_id', 0)->orWhereNull('ad.id');
            })
            ->orderBy('u.id')
            ->get(['u.id', 'u.name', 'u.address_id']);

        $usuarios = [];

        foreach ($filas as $fila) {
            $usuarios[] = ['id' => (int) $fila->id, 'name' => $fila->name, 'address_id' => $fila->address_id];
        }

        return $usuarios;
    }

    /**
     * @param  array  $m
     * @return void
     */
    private function reportar(array $m)
    {
        $this->line('');

        if ($m['crear']) {
            $this->line('Sucursal: el comercio NO tiene sucursales → se crea "'.$m['nombre'].'"'
                .(is_null($m['domicilio']) ? ' (sin domicilio: no hay dirección de la empresa ni de ARCA)' : ' con domicilio "'.$m['domicilio'].'"')
                .' y se marca principal.');
            $this->line('Usuarios sin sucursal que pasan a la principal: '.count($m['empleados'])
                .(count($m['empleados']) ? ' ('.implode(', ', array_map(function ($u) { return $u['id'].' '.$u['name']; }, $m['empleados'])).')' : '').'.');
        } else {
            $this->line('Sucursal: el comercio ya tiene '.$m['sucursales'].' sucursal(es). Principal: '
                .$m['principal']->id.' "'.$m['principal']->street.'"'
                .($m['marcar'] ? ' (se marca como principal: hoy no hay exactamente una marcada).' : ' (ya marcada).'));
            $this->line('Empleados: no se tocan (el comercio ya tenía sucursales).');
        }

        $c = $m['casos'];

        $this->line('Artículos sin fila: '.count($m['articulos'])
            .' · con stock (movimiento a la principal): '.$c['con_stock']
            .' · en 0 o sin stock (fila en 0, sin movimiento): '.$c['en_cero_o_null']
            .' · en la papelera (fila directa, sin movimiento): '.$c['papelera']
            .' · con variantes: '.$c['con_variantes'].' ('.$c['variantes'].' variantes sin fila)'
            .' · con fila solo en un domicilio de comprador (cuentan como sin fila; la principal recibe el stock menos esa fila): '.$c['solo_comprador'].'.');

        $this->warn(self::AVISO_HORARIO);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  APLICAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * @param  User    $dueno
     * @param  array   $m
     * @param  string  $nombre
     * @return int
     */
    private function aplicar($dueno, array $m, $nombre)
    {
        // Respaldo general: las sucursales y los usuarios del comercio como estaban.
        $this->escribir_json([
            'tipo'       => 'comercio',
            'user_id'    => $dueno->id,
            'sucursales' => DB::table('addresses')->where('user_id', $dueno->id)->whereNull('buyer_id')->orderBy('id')->get()->all(),
            'usuarios'   => DB::table('users')->where(function ($q) use ($dueno) {
                                $q->where('id', $dueno->id)->orWhere('owner_id', $dueno->id);
                            })->orderBy('id')->get(['id', 'owner_id', 'address_id'])->all(),
        ]);

        $principal = DB::transaction(function () use ($dueno, $m, $nombre) {
            return $this->asegurar_sucursal($dueno, $m, $nombre);
        });

        $this->info('Sucursal principal: '.$principal->id.' "'.$principal->street.'".');

        $owner = User::find($dueno->id);

        $ok = 0;
        $revertidos = [];

        foreach ($m['articulos'] as $a) {

            try {
                DB::transaction(function () use ($a, $principal, $owner) {
                    $this->migrar_articulo($a, $principal, $owner);
                });

                $ok++;

            } catch (\Throwable $e) {

                if ($e->getCode() === 9002) {
                    // El respaldo no se pudo escribir: no se sigue sin respaldo.
                    $this->error($e->getMessage());

                    return 1;
                }

                $revertidos[] = $a['id'];

                $this->warn('Artículo '.$a['id'].': '.$e->getMessage().' → se revirtió, quedó como estaba.');

                $this->escribir_json(['tipo' => 'revertido', 'article_id' => $a['id'], 'motivo' => $e->getMessage()], false);
            }
        }

        $this->line('');
        $this->info('Listo: '.$ok.' artículo(s) migrados'.(count($revertidos) ? ', '.count($revertidos).' revertidos (ids: '.implode(', ', array_slice($revertidos, 0, 50)).')' : '').'.');
        $this->line('Respaldo y reversión en: '.$this->carpeta);
        $this->warn(self::AVISO_HORARIO);

        return count($revertidos) ? 1 : 0;
    }

    /**
     * b y c: crea la principal (si no hay sucursales) o asegura la marca; si la creó, asigna los
     * usuarios sin sucursal. Deja su reversión en el SQL.
     *
     * @param  User    $dueno
     * @param  array   $m
     * @param  string  $nombre
     * @return Address
     */
    private function asegurar_sucursal($dueno, array $m, $nombre)
    {
        if (!$m['crear']) {

            $antes = DB::table('addresses')->where('user_id', $dueno->id)->whereNull('buyer_id')->pluck('default_address', 'id')->all();

            $principal = Address::asegurar_principal($dueno->id);

            $sql = "-- Marca de sucursal principal (default_address) como estaba\n";

            foreach ($antes as $id => $valor) {
                $sql .= 'UPDATE addresses SET default_address = '.$this->literal($valor).' WHERE id = '.(int) $id.";\n";
            }

            $this->escribir_sql($sql."\n");

            return $principal;
        }

        $principal = Address::create([
            'street'          => $nombre,
            'street_number'   => $m['domicilio'],
            'user_id'         => $dueno->id,
            'default_address' => 1,
        ]);

        $sql = "-- Sucursal principal creada por la migración\n"
            .'DELETE FROM address_article WHERE address_id = '.(int) $principal->id.";\n"
            .'DELETE FROM address_article_variant WHERE address_id = '.(int) $principal->id.";\n"
            .'DELETE FROM addresses WHERE id = '.(int) $principal->id.";\n";

        foreach ($m['empleados'] as $usuario) {

            DB::table('users')->where('id', $usuario['id'])->update(['address_id' => $principal->id]);

            $sql .= 'UPDATE users SET address_id = '.$this->literal($usuario['address_id']).' WHERE id = '.(int) $usuario['id'].' AND address_id = '.(int) $principal->id.";\n";
        }

        $this->escribir_sql($sql."\n");

        $this->escribir_json(['tipo' => 'sucursal_creada', 'address_id' => $principal->id, 'usuarios' => $m['empleados']]);

        return $principal->fresh();
    }

    /**
     * d y e para UN artículo (adentro de su transacción): respaldo, filas, verificación, reversión.
     *
     * @param  array    $a
     * @param  Address  $principal
     * @param  User     $owner
     * @return void
     * @throws \RuntimeException si el stock no quedó idéntico (la transacción se revierte).
     */
    private function migrar_articulo(array $a, $principal, $owner)
    {
        $id = (int) $a['id'];

        // Estado previo, leído con candado, y el respaldo ANTES de tocar nada.
        $articulo_antes = DB::table('articles')->where('id', $id)->lockForUpdate()->first(['id', 'stock', 'stock_updated_at']);

        // Las variantes también con candado: el stock que se usa abajo es ESTE, no el de medir().
        $todas_las_variantes = DB::table('article_variants')->where('article_id', $id)->orderBy('id')->lockForUpdate()->get(['id', 'stock'])->all();

        $variante_bajo_candado = [];

        foreach ($todas_las_variantes as $v) {
            $variante_bajo_candado[(int) $v->id] = $v;
        }

        $max_movimiento = (int) DB::table('stock_movements')->max('id');

        $filas_antes = DB::table('address_article')->where('article_id', $id)->pluck('id')->all();

        $filas_variante_antes = count($todas_las_variantes)
            ? DB::table('address_article_variant')->whereIn('article_variant_id', array_map(function ($v) { return $v->id; }, $todas_las_variantes))->pluck('id')->all()
            : [];

        $this->escribir_json([
            'tipo'           => 'articulo',
            'article_id'     => $id,
            'stock'          => $articulo_antes->stock,
            'stock_updated_at' => $articulo_antes->stock_updated_at,
            'variantes'      => $todas_las_variantes,
            'address_article'         => DB::table('address_article')->where('article_id', $id)->get()->all(),
            'address_article_variant' => count($filas_variante_antes) ? DB::table('address_article_variant')->whereIn('id', $filas_variante_antes)->get()->all() : [],
        ]);

        $stock_antes = is_null($articulo_antes->stock) ? 0.0 : (float) $articulo_antes->stock;

        $ahora = Carbon::now();

        if (count($a['variantes']) === 0) {

            /*
             * Lo que va a la principal: el stock MENOS lo que ya suman las filas que tiene (las de un
             * domicilio de comprador, que no cuentan como sucursal pero sí suman en articles.stock). Sin
             * filas, es el stock entero.
             */
            $a_la_principal = round($stock_antes - (float) DB::table('address_article')->where('article_id', $id)->sum('amount'), 2);

            if ($a['papelera'] || $a_la_principal == 0.0) {

                // Sin movimiento: la fila directa (con su stock en la papelera, en 0 si no) y el null → 0.
                DB::table('address_article')->insert([
                    'article_id' => $id,
                    'address_id' => $principal->id,
                    'amount'     => $a_la_principal,
                ]);

                if (is_null($articulo_antes->stock)) {
                    DB::table('articles')->where('id', $id)->update(['stock' => 0]);
                }

            } else {

                $this->movimiento($id, null, $a_la_principal, $principal, $owner);
            }

        } else {

            $directas = false;

            foreach ($a['variantes'] as $v) {

                // El stock releído bajo candado, no el de medir(): pudo moverse en el medio.
                $bajo_candado = isset($variante_bajo_candado[(int) $v['id']]) ? $variante_bajo_candado[(int) $v['id']] : null;

                if (is_null($bajo_candado)) {
                    continue;
                }

                $stock_crudo_variante = $bajo_candado->stock;

                $stock_variante = round((is_null($stock_crudo_variante) ? 0.0 : (float) $stock_crudo_variante)
                    - (float) DB::table('address_article_variant')->where('article_variant_id', (int) $v['id'])->sum('amount'), 2);

                if ($a['papelera'] || $stock_variante == 0.0) {

                    DB::table('address_article_variant')->insert([
                        'article_variant_id' => $v['id'],
                        'address_id'         => $principal->id,
                        'amount'             => $stock_variante,
                        'created_at'         => $ahora,
                        'updated_at'         => $ahora,
                    ]);

                    if (is_null($stock_crudo_variante)) {
                        DB::table('article_variants')->where('id', $v['id'])->update(['stock' => 0]);
                    }

                    $directas = true;

                } else {

                    $this->movimiento($id, $v['id'], $stock_variante, $principal, $owner);
                }
            }

            // Con filas puestas a mano, el recálculo de las variantes y del artículo lo hace la misma
            // función que usa el motor (el movimiento ya la llama solo).
            if ($directas) {
                $modelo = Article::withTrashed()->find($id);

                ArticleHelper::setArticleStockFromAddresses($modelo, false, $owner->id);
            }
        }

        // e. Verificación: el stock del artículo y el de cada variante, idénticos (null ≡ 0).
        $stock_despues = DB::table('articles')->where('id', $id)->value('stock');
        $stock_despues = is_null($stock_despues) ? 0.0 : (float) $stock_despues;

        if (abs($stock_despues - $stock_antes) > self::TOLERANCIA) {
            throw new \RuntimeException('el stock del artículo iba a quedar en '.$stock_despues.' y era '.$stock_antes);
        }

        foreach ($todas_las_variantes as $v) {

            $antes = is_null($v->stock) ? 0.0 : (float) $v->stock;
            $despues = DB::table('article_variants')->where('id', $v->id)->value('stock');
            $despues = is_null($despues) ? 0.0 : (float) $despues;

            if (abs($despues - $antes) > self::TOLERANCIA) {
                throw new \RuntimeException('el stock de la variante '.$v->id.' iba a quedar en '.$despues.' y era '.$antes);
            }
        }

        // g. La reversión de este artículo, antes del commit (si no se puede escribir, se revierte).
        $sql = '-- Artículo '.$id."\nSTART TRANSACTION;\n"
            .'DELETE FROM stock_movements WHERE article_id = '.$id.' AND id > '.$max_movimiento.' AND observations LIKE '.$this->literal(self::OBSERVACION.'%').";\n"
            .'DELETE FROM address_article WHERE article_id = '.$id.(count($filas_antes) ? ' AND id NOT IN ('.implode(',', array_map('intval', $filas_antes)).')' : '').";\n";

        if (count($todas_las_variantes)) {
            $sql .= 'DELETE FROM address_article_variant WHERE article_variant_id IN ('.implode(',', array_map(function ($v) { return (int) $v->id; }, $todas_las_variantes)).')'
                .(count($filas_variante_antes) ? ' AND id NOT IN ('.implode(',', array_map('intval', $filas_variante_antes)).')' : '').";\n";

            foreach ($todas_las_variantes as $v) {
                $sql .= 'UPDATE article_variants SET stock = '.$this->literal($v->stock).' WHERE id = '.(int) $v->id.";\n";
            }
        }

        $sql .= 'UPDATE articles SET stock = '.$this->literal($articulo_antes->stock).', stock_updated_at = '.$this->literal($articulo_antes->stock_updated_at).' WHERE id = '.$id.";\nCOMMIT;\n\n";

        $this->escribir_sql($sql);
    }

    /**
     * El movimiento "Creacion de deposito" por el stock entero, por el motor.
     *
     * @param  int       $article_id
     * @param  int|null  $variant_id
     * @param  float     $amount
     * @param  Address   $principal
     * @param  User      $owner
     * @return void
     */
    private function movimiento($article_id, $variant_id, $amount, $principal, $owner)
    {
        $ct = new StockMovementController();

        $ct->crear([
            'model_id'                      => $article_id,
            'amount'                        => $amount,
            'to_address_id'                 => $principal->id,
            'article_variant_id'            => $variant_id,
            'concepto_stock_movement_name'  => self::CONCEPTO,
            'observations'                  => self::OBSERVACION,
            'sin_unidades_individuales'     => true,
            'sin_materializar'              => true,
            'sin_sincronizar'               => true,
            // El stock no cambia: la fecha de "stock actualizado" del artículo no se pisa.
            'sin_stock_updated_at'          => true,
        ], false, $owner, $owner->id);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  RESPALDO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Abre la carpeta y los dos archivos ANTES de la primera escritura. Si no se puede, no se escribe nada.
     *
     * @param  int  $user_id
     * @return bool
     */
    private function abrir_respaldo($user_id)
    {
        $raiz = trim((string) $this->option('salida'));

        if ($raiz === '') {
            $raiz = storage_path('app/'.self::CARPETA);
        }

        $base = rtrim($raiz, '/\\').DIRECTORY_SEPARATOR.Carbon::now()->format('Ymd-His').'-user'.$user_id;

        $carpeta = $base;
        $n = 1;

        while (file_exists($carpeta)) {
            $n++;
            $carpeta = $base.'-'.$n;
        }

        if (!@mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
            $this->error('No se pudo crear la carpeta del respaldo: '.$carpeta.'. No se escribió nada.');

            return false;
        }

        $this->respaldo = @fopen($carpeta.DIRECTORY_SEPARATOR.'respaldo.jsonl', 'x');
        $this->sql = @fopen($carpeta.DIRECTORY_SEPARATOR.'reversion.sql', 'x');

        if ($this->respaldo === false || $this->sql === false) {
            $this->error('No se pudieron abrir los archivos del respaldo en '.$carpeta.'. No se escribió nada.');

            return false;
        }

        $this->carpeta = $carpeta;

        $this->escribir_sql(implode("\n", [
            '-- Reversión de sucursales:migrar-a-principal',
            '-- Base: '.DB::connection()->getDatabaseName().' · dueño '.$user_id.' · '.Carbon::now()->toDateTimeString(),
            '-- Orden del archivo: primero el bloque de la sucursal (la marca de principal, o la sucursal creada con los',
            '-- usuarios), después un bloque por artículo, en el orden en que se migraron.',
            '-- Para revertir: correr los bloques de artículo y RECIÉN DESPUÉS el de la sucursal (borrar la sucursal antes',
            '-- dejaría filas apuntando a un id que no existe). Cada bloque de artículo es completo por sí solo.',
            '-- OJO: vale solo INMEDIATAMENTE después de migrar. Los UPDATE dejan el stock como estaba ANTES de la',
            '-- migración, así que si hubo ventas, compras o ajustes en el medio, los pisa. Por eso se corre fuera del horario.',
            '-- ESCRIBE: correr con criterio.',
            '',
            '',
        ]));

        return true;
    }

    /**
     * @return void
     */
    private function cerrar_respaldo()
    {
        if (is_resource($this->respaldo)) {
            fclose($this->respaldo);
        }

        if (is_resource($this->sql)) {
            fclose($this->sql);
        }
    }

    /**
     * Una línea JSON en el respaldo.
     *
     * @param  array  $datos
     * @param  bool   $estricto  Si es true y no se puede escribir, lanza (código 9002): no se sigue sin respaldo.
     * @return void
     */
    private function escribir_json(array $datos, $estricto = true)
    {
        $linea = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";

        if ((!is_resource($this->respaldo) || @fwrite($this->respaldo, $linea) === false) && $estricto) {
            throw new \RuntimeException('No se pudo escribir el respaldo en '.$this->carpeta.': se corta la corrida.', 9002);
        }
    }

    /**
     * @param  string  $sql
     * @return void
     */
    private function escribir_sql($sql)
    {
        if (!is_resource($this->sql) || @fwrite($this->sql, $sql) === false) {
            throw new \RuntimeException('No se pudo escribir el SQL de reversión en '.$this->carpeta.': se corta la corrida.', 9002);
        }

        fflush($this->sql);
    }

    /**
     * Un valor como literal SQL.
     *
     * @param  mixed  $valor
     * @return string
     */
    private function literal($valor)
    {
        if (is_null($valor)) {
            return 'NULL';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
            return (string) $valor;
        }

        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $valor)."'";
    }
}
