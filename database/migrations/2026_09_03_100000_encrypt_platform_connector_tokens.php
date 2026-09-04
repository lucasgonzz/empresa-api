<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra los secretos que `platform_connectors` y `platforms` venian guardando en TEXTO PLANO.
 *
 * Por que ahora: hasta esta mision los tokens de cobro de Mercado Pago vivian en
 * `online_configurations.mp_access_token`, que si tiene cast `encrypted`. Mudarlos a
 * `platform_connectors` tal como estaba la tabla habria sido un retroceso de seguridad: los
 * mismos secretos, pero legibles con un `SELECT`. Esta migracion sube el nivel de la tabla
 * generica al que ya tenia Mercado Pago, y de paso arregla a Mercado Libre y Tienda Nube, que
 * venian en plano desde que la tabla existe.
 *
 * Columnas cifradas:
 * - `platform_connectors.access_token` y `.refresh_token` (tokens OAuth de cada comercio).
 * - `platforms.client_secret` (secreto de la APLICACION de ComercioCity en cada plataforma).
 *
 * IDEMPOTENTE a proposito: se recorre fila por fila y solo se cifra lo que todavia no lo esta.
 * El criterio es el unico confiable que hay — intentar `Crypt::decryptString()` sobre el valor:
 * si funciona, ya estaba cifrado y se deja como esta; si tira `DecryptException`, era texto
 * plano. Asi la migracion se puede correr dos veces seguidas sin doble cifrado (que dejaria el
 * token irrecuperable) y sin dejar filas a medias si se corto a la mitad la primera vez.
 *
 * Se escribe con `DB::table()` y NO con los modelos Eloquent: los modelos ya tienen el cast
 * `encrypted` puesto en esta misma mision, asi que guardar por ahi cifraria una segunda vez el
 * valor que esta migracion acaba de cifrar.
 */
class EncryptPlatformConnectorTokens extends Migration
{
    /**
     * Cifra los valores en texto plano de las tres columnas.
     *
     * @return void
     */
    public function up()
    {
        $this->convertir_columna('platform_connectors', 'access_token', true);
        $this->convertir_columna('platform_connectors', 'refresh_token', true);
        $this->convertir_columna('platforms', 'client_secret', true);
    }

    /**
     * Descifra las tres columnas para dejarlas como estaban antes de esta migracion.
     *
     * @return void
     */
    public function down()
    {
        $this->convertir_columna('platform_connectors', 'access_token', false);
        $this->convertir_columna('platform_connectors', 'refresh_token', false);
        $this->convertir_columna('platforms', 'client_secret', false);
    }

    /**
     * Recorre una columna fila por fila cifrando (o descifrando) solo lo que haga falta.
     *
     * @param string $tabla Tabla a recorrer.
     * @param string $columna Columna con el secreto.
     * @param bool $cifrar true para cifrar (up), false para descifrar (down).
     * @return void
     */
    protected function convertir_columna($tabla, $columna, $cifrar)
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) {
            return;
        }

        // Se lee de a tandas para no cargar en memoria una tabla grande. `orderBy('id')` es
        // obligatorio para que `chunk` pagine de forma estable.
        DB::table($tabla)
            ->select(['id', $columna])
            ->whereNotNull($columna)
            ->where($columna, '!=', '')
            ->orderBy('id')
            ->chunk(200, function ($filas) use ($tabla, $columna, $cifrar) {
                foreach ($filas as $fila) {
                    $valor = $fila->{$columna};

                    if (is_null($valor) || $valor === '') {
                        continue;
                    }

                    $nuevo_valor = $cifrar
                        ? $this->cifrar_si_hace_falta($valor)
                        : $this->descifrar_si_hace_falta($valor);

                    if (is_null($nuevo_valor)) {
                        continue;
                    }

                    DB::table($tabla)
                        ->where('id', $fila->id)
                        ->update([$columna => $nuevo_valor]);
                }
            });
    }

    /**
     * Devuelve el valor cifrado, o null si ya estaba cifrado (no hay nada que hacer).
     *
     * @param string $valor Valor crudo leido de la base.
     * @return string|null
     */
    protected function cifrar_si_hace_falta($valor)
    {
        if ($this->esta_cifrado($valor)) {
            return null;
        }

        return Crypt::encryptString($valor);
    }

    /**
     * Devuelve el valor en texto plano, o null si ya estaba en plano (no hay nada que hacer).
     *
     * @param string $valor Valor crudo leido de la base.
     * @return string|null
     */
    protected function descifrar_si_hace_falta($valor)
    {
        if (!$this->esta_cifrado($valor)) {
            return null;
        }

        return Crypt::decryptString($valor);
    }

    /**
     * Unico chequeo confiable de "esto ya esta cifrado con la APP_KEY de esta instancia":
     * intentar descifrarlo. Un token OAuth en texto plano no es un payload valido de Laravel
     * (que es un JSON en base64 con iv/value/mac), asi que tira DecryptException.
     *
     * @param string $valor
     * @return bool
     */
    protected function esta_cifrado($valor)
    {
        try {
            Crypt::decryptString($valor);

            return true;
        } catch (DecryptException $e) {
            return false;
        }
    }
}
