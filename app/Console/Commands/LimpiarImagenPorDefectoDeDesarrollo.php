<?php

namespace App\Console\Commands;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * LimpiarImagenPorDefectoDeDesarrollo (15/10/2026)
 *
 * Pone en NULL `online_configurations.default_article_image_url` cuando guarda una URL de
 * desarrollo (la que contiene `empresa.local` o `localhost`).
 *
 * POR QUE EXISTE. Hasta el 16/10/2026 `UserSetupHelper::tienda()` escribia
 * `http://empresa.local:8000/storage/169705209718205.jpg` en CADA comercio nuevo. `tienda-spa`
 * usa esa columna como foto de todo articulo sin imagen (`articleImage()`): el visitante veia
 * iconos rotos en el buscador, "Imagen no encontrada" en las tarjetas y, como la URL es `http`
 * dentro de una tienda `https`, contenido mixto (el navegador le saca el candado a la pagina).
 * El alta ya no la escribe; este comando limpia a los comercios que NACIERON con ella.
 *
 * POR QUE NULL Y NO OTRA IMAGEN. NULL es un estado que la tienda ya soporta y que el dueño
 * resuelve despues desde la configuracion de la tienda. Reemplazarla por una imagen "generica"
 * (por ejemplo el logo de ComercioCity, que usan las demos a proposito) mostraria la marca de
 * ComercioCity como si fuera la del negocio de un cliente real.
 *
 * QUE TOCA Y QUE NO. Solo la columna `default_article_image_url` (y, porque la escritura es un
 * UPDATE del builder de Eloquent, el `updated_at` de esa fila), solo cuando el HOST de la URL es
 * una de las marcas de `MARCAS_DE_DESARROLLO` o un subdominio de ella (sin distinguir
 * mayusculas). Una URL https real, un valor vacio o un NULL no se tocan. Recorre solo a los
 * DUEÑOS (`users.owner_id IS NULL`): la configuracion de la tienda es del comercio, no del
 * empleado.
 *
 * HOST Y NO SUBCADENA. El pedido decia "que contenga empresa.local o localhost". Buscar la
 * subcadena en cualquier parte de la URL limpiaria tambien una imagen real de un cliente cuyo
 * dominio o archivo se parece (`https://www.localhostel.com.ar/...`, `.../logo-localhost.png`):
 * dos revisores independientes lo marcaron el 16/10/2026. Comparar el host cubre todo lo que
 * "contiene" queria cubrir (`http://empresa.local:8000/...`, `http://localhost/...`, sin esquema,
 * en mayusculas) sin ese riesgo.
 *
 * LO QUE NO LIMPIA PERO SI MUESTRA. Una imagen por defecto `http://` que NO es de desarrollo
 * (otro host, una IP) no se toca, pero se lista como `[http_sin_limpiar]`: dentro de una tienda
 * https es contenido mixto igual que la de desarrollo, y decidir que hacer con ella es del dueño.
 *
 * SIN RASTRO EN `audit_logs`. El UPDATE masivo no pasa por Eloquent, asi que la limpieza no
 * genera fila de auditoria: el unico registro del valor viejo es la salida de este comando
 * (`valor_viejo=`). Si se corre fuera de la conversacion, guardar la salida.
 *
 * Es idempotente: la segunda corrida no encuentra nada y lo dice. Por defecto es de SOLO
 * LECTURA (mismo criterio que `normalizar_api_url` y `articles:normalizar-margen-cero`): hay que
 * pasar --aplicar para que escriba.
 *
 *     php artisan tienda:limpiar-imagen-por-defecto-de-desarrollo              # reporta
 *     php artisan tienda:limpiar-imagen-por-defecto-de-desarrollo --aplicar    # escribe
 *     php artisan tienda:limpiar-imagen-por-defecto-de-desarrollo --aplicar --user_id=2700
 *
 * Se corre a mano, por cliente. No esta agendado en el scheduler: `Kernel::commands()` ya carga
 * esta carpeta entera, no hace falta registrarlo en ningun lado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class LimpiarImagenPorDefectoDeDesarrollo extends Command
{
    /**
     * Hosts que delatan una URL de desarrollo. Una URL es de desarrollo cuando su HOST (en
     * minusculas) es igual a una de estas marcas o es un subdominio de ella: la URL que motivo el
     * comando era `http://empresa.local:8000/...` y `localhost` cubre a quien probo con
     * `http://localhost:8000/...`.
     *
     * Para agregar un host de desarrollo nuevo se suma aca, nunca en un segundo comando.
     *
     * @var string[]
     */
    const MARCAS_DE_DESARROLLO = ['empresa.local', 'localhost'];

    /**
     * Nombre y firma del comando.
     *
     * @var string
     */
    protected $signature = 'tienda:limpiar-imagen-por-defecto-de-desarrollo
                            {--aplicar : Escribe los cambios. Sin este flag el comando solo reporta.}
                            {--user_id= : Limita el barrido a un solo dueño.}';

    /**
     * Descripcion del comando.
     *
     * @var string
     */
    protected $description = 'Pone en NULL la imagen por defecto de la tienda (online_configurations.default_article_image_url) cuando es una URL de desarrollo (empresa.local o localhost).';

    /**
     * Indica si una URL es de desarrollo, o sea si su HOST es alguna de las marcas de
     * `MARCAS_DE_DESARROLLO` o un subdominio de ella, sin distinguir mayusculas.
     *
     * Es publico y estatico para que el test lo ejercite solo, y para que nadie copie esta regla
     * a otro lado: si hay que cambiar que cuenta como "de desarrollo", se cambia aca. Un NULL, un
     * valor que no es texto o un texto vacio (o de solo espacios) no es una URL de desarrollo:
     * son los estados "sin imagen por defecto" que el comando tiene que respetar.
     *
     * @param  mixed  $url  Valor crudo de la columna.
     * @return bool
     */
    public static function es_url_de_desarrollo($url)
    {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }

        $host = self::host_de($url);

        if ($host === '') {
            return false;
        }

        foreach (self::MARCAS_DE_DESARROLLO as $marca) {
            /* El host es la marca misma o un subdominio suyo ("www.empresa.local"). El punto
               delante evita que "miempresa.local" o "localhostel.com.ar" cuenten. */
            if ($host === $marca || substr($host, -(strlen($marca) + 1)) === '.' . $marca) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si una URL es `http://` comun (sin cifrar) y NO es de desarrollo: la que el comando
     * no limpia pero si lista, porque dentro de una tienda https es contenido mixto.
     *
     * @param  mixed  $url  Valor crudo de la columna.
     * @return bool
     */
    public static function es_http_sin_cifrar($url)
    {
        if (!is_string($url)) {
            return false;
        }

        return stripos(ltrim($url), 'http://') === 0 && !self::es_url_de_desarrollo($url);
    }

    /**
     * El host de una URL, en minusculas y sin el punto final, o '' si no hay forma de sacarlo.
     *
     * Una URL guardada sin esquema (`empresa.local:8000/storage/foto.jpg`) hace que `parse_url`
     * tome "empresa.local" como esquema y devuelva host nulo: por eso, si no trae `esquema://` ni
     * empieza con `//`, se le antepone `//` antes de parsear.
     *
     * mb_strtolower y no strtolower: en PHP 7.4 strtolower depende del locale del proceso (con un
     * locale turco, "I" no baja a "i" y "LOCALHOST" se escaparia).
     *
     * @param  string  $url  Valor crudo de la columna.
     * @return string
     */
    protected static function host_de($url)
    {
        $url = trim($url);

        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && substr($url, 0, 2) !== '//') {
            $url = '//' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        return mb_strtolower(rtrim($host, '.'), 'UTF-8');
    }

    /**
     * Barre las configuraciones de tienda de los dueños y limpia (con --aplicar) las que tienen
     * una URL de desarrollo.
     *
     * @return int  0 siempre que el comando haya podido correr (incluso si no encontro nada o
     *              si solo reporto); 1 si --user_id no es un entero positivo (ahi no hay forma de
     *              saber a que dueño se queria acotar) o si es un entero pero no es el id de un
     *              dueño: un empleado o un id que no existe.
     */
    public function handle()
    {
        /* Flag --aplicar: sin el, el comando no escribe nada. */
        $aplicar = (bool) $this->option('aplicar');

        $user_id = $this->option('user_id');

        /*
         * "Se escribio --user_id" y "option() devolvio un valor" NO son lo mismo. `{--user_id=}` es
         * una opcion con valor OPCIONAL: `--user_id` pelado (sin `=` ni valor) llega como null,
         * igual que si no se hubiera escrito, y `--user_id=` llega como ''. Si "sin valor" se
         * tomara como "sin filtro", `--aplicar --user_id $ID` con `$ID` vacio en el shell terminaria
         * limpiando A TODOS los dueños de la base en vez de uno. Por eso se mira tambien la linea de
         * comandos con hasParameterOption(), que anda igual con la consola real y con el input de
         * `Artisan::call()` (el de los tests).
         */
        $acotar = !is_null($user_id) || $this->input->hasParameterOption('--user_id');

        /*
         * Un --user_id mal tipeado no se castea en silencio: (int) "2700x" da 2700 y con --aplicar
         * el comando escribiria sobre un dueño que nadie pidio. Pelado, vacio, no numerico, cero:
         * se corta antes de leer nada.
         */
        if ($acotar && (!ctype_digit((string) $user_id) || (int) $user_id < 1)) {
            $this->error('--user_id tiene que ser un numero entero positivo (recibi "' . (string) $user_id . '"). No se hizo nada.');
            return 1;
        }

        if ($acotar) {
            $this->line('Limitado al user_id ' . (int) $user_id . '.');
        }

        /* Contadores del resumen final. */
        $duenos_revisados          = 0;
        $configuraciones_revisadas = 0;
        $con_url_de_desarrollo     = 0;
        $con_http_sin_limpiar      = 0;
        $limpiadas                 = 0;

        /*
         * Solo DUEÑOS (owner_id nulo), nunca User::all(): una tabla users de un cliente grande
         * trae empleados por cientos y la configuracion de la tienda no es de ellos.
         * Se selecciona solo el id porque es lo unico que se necesita para ir a buscar las
         * configuraciones (la tabla users es ancha). chunkById pagina por id y no por offset.
         */
        User::query()
            ->select('id')
            ->whereNull('owner_id')
            ->when($acotar, function ($query) use ($user_id) {
                return $query->where('id', (int) $user_id);
            })
            ->orderBy('id')
            ->chunkById(200, function ($duenos) use (&$duenos_revisados, &$configuraciones_revisadas, &$con_url_de_desarrollo, &$con_http_sin_limpiar, &$limpiadas, $aplicar) {

                $ids_de_duenos = $duenos->pluck('id')->all();
                $duenos_revisados += count($ids_de_duenos);

                /* Una sola consulta por tanda de 200 dueños, no una por dueño. */
                $configuraciones = OnlineConfiguration::query()
                    ->whereIn('user_id', $ids_de_duenos)
                    ->orderBy('id')
                    ->get(['id', 'user_id', 'default_article_image_url']);

                foreach ($configuraciones as $configuracion) {
                    $configuraciones_revisadas++;

                    /* Valor tal como esta en la base: es el que se compara al escribir. */
                    $valor_leido = $configuracion->default_article_image_url;

                    if (!self::es_url_de_desarrollo($valor_leido)) {

                        /*
                         * Una http:// que no es de desarrollo no se toca (no es lo que se pidio
                         * limpiar y puede ser la imagen elegida por el dueño), pero se muestra:
                         * dentro de una tienda https es el mismo contenido mixto.
                         */
                        if (self::es_http_sin_cifrar($valor_leido)) {
                            $con_http_sin_limpiar++;
                            $this->line('[http_sin_limpiar] user_id=' . $configuracion->user_id
                                . ' | online_configuration_id=' . $configuracion->id
                                . ' | valor=' . $valor_leido);
                        }

                        continue;
                    }

                    $con_url_de_desarrollo++;

                    /* El valor viejo queda en la salida: es el unico registro de lo que habia. */
                    $detalle = 'user_id=' . $configuracion->user_id
                        . ' | online_configuration_id=' . $configuracion->id
                        . ' | valor_viejo=' . $valor_leido;

                    if (!$aplicar) {
                        $this->line('[para_limpiar] ' . $detalle);
                        continue;
                    }

                    /*
                     * Compare-and-set: el UPDATE solo pega si la columna sigue valiendo lo que
                     * se leyo. Si el dueño la cambio entre la lectura y esta escritura (por
                     * ejemplo, justo cargo su propia imagen), el UPDATE no encuentra fila y el
                     * valor nuevo se respeta. Sin esto el comando pisaria una imagen real con
                     * NULL. Es UPDATE masivo del builder y no ->save(): no dispara eventos de
                     * modelo, que aca no corresponden para una limpieza de datos.
                     */
                    $filas_tocadas = OnlineConfiguration::query()
                        ->where('id', $configuracion->id)
                        ->where('default_article_image_url', $valor_leido)
                        ->update(['default_article_image_url' => null]);

                    if ($filas_tocadas > 0) {
                        $limpiadas++;
                        $this->line('[limpiada] ' . $detalle);
                    } else {
                        $this->warn('[cambio_mientras_corria] ' . $detalle . ' | no se escribio nada: el valor ya no era ese.');
                    }
                }
            });

        /*
         * --user_id acota a UN dueño. Si el barrido no revisó a ninguno, ese id no es el de un
         * dueño: no existe, o es un empleado (la configuracion de la tienda es del dueño). Decir
         * "Nada para hacer" con codigo 0 seria enganoso: con "--user_id=19" (el id del CLIENTE en
         * el admin, no el del comercio) parece que el comercio esta limpio y no se reviso nada.
         */
        if ($acotar && $duenos_revisados === 0) {
            $this->error('El user_id ' . (int) $user_id . ' no es el de un dueño (no existe, o es un empleado). No se reviso nada. Ojo: es el id del usuario dueño en la base del cliente, no el id del cliente en el admin.');
            return 1;
        }

        $this->info('--- Resumen ---');
        $this->info('duenos_revisados: ' . $duenos_revisados);
        $this->info('configuraciones_revisadas: ' . $configuraciones_revisadas);
        $this->info('con_url_de_desarrollo: ' . $con_url_de_desarrollo);
        $this->info('limpiadas: ' . $limpiadas);
        $this->info('con_http_sin_limpiar: ' . $con_http_sin_limpiar);

        if ($con_url_de_desarrollo === 0) {
            $this->info('No hay ninguna imagen por defecto de desarrollo. Nada para hacer.');
        }

        /* Las http:// que no son de desarrollo quedan como estaban: se avisa para que no pasen de largo. */
        if ($con_http_sin_limpiar > 0) {
            $this->comment('Las [http_sin_limpiar] NO se tocaron (no son de desarrollo). Dentro de una tienda https son contenido mixto: conviene que el dueño cargue una https.');
        }

        /* Modo reporte con algo para limpiar: se aclara que no se escribio nada. */
        if (!$aplicar && $con_url_de_desarrollo > 0) {
            $this->comment('Corriste sin --aplicar: no se escribio nada. Volve a correr con --aplicar para limpiarlas.');
        }

        return 0;
    }
}
