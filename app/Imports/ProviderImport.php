<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProviderImport implements ToCollection, WithMultipleSheets
{

    /**
     * Indice 0-based de la hoja a importar. Default 0 = primera hoja.
     *
     * @var int
     */
    private $hoja = 0;

    /**
     * Nombre de la hoja elegida, cuando el cliente lo manda. Gana sobre el indice.
     *
     * @var string|null
     */
    private $hoja_nombre = null;

    /**
     * Checkbox unico por importacion: que hacer con las celdas VACIAS de las columnas
     * que el usuario mapeo.
     *
     *   false (default): la celda vacia se saltea y el valor que ya tiene el proveedor
     *                    queda como estaba. Es el comportamiento de siempre.
     *   true:            la celda vacia ESCRIBE vacio (null) sobre el proveedor existente.
     *
     * @var bool
     */
    private $vaciar_valores_en_blanco = false;

    /**
     * Proveedores cuyo saldo del Excel NO se cargo porque su cuenta ya tenia movimientos y su
     * saldo es OTRO, como [provider_id => ['nombre' => ..., 'excel' => ..., 'cuenta' => ...]].
     * Se avisan al final, en la notificacion (getInfoToShow()), con los dos montos.
     *
     * El saldo de la importacion de proveedores es siempre un saldo INICIAL: va solo en una
     * cuenta vacia y nunca ajusta (ver LocalImportHelper::setSaldoInicial()). Antes ese "no se
     * cargo" era silencioso (mision importacion-proveedores-saldo-inicial, 8/10/2026). Si la
     * cuenta ya tiene el saldo del Excel ('sin_cambios', p. ej. la segunda pasada del mismo
     * archivo) no se anota: no hay nada que avisar.
     *
     * @var array
     */
    private $saldos_no_cargados = [];

    /**
     * Tope de proveedores que nombra el aviso de saldos no cargados. Del resto se dice cuantos
     * son. El evento de la notificacion viaja por Pusher, que corta en 10 KB: con unos 300 nombres
     * se perdia la notificacion ENTERA de fin de importacion, boton incluido.
     *
     * Es el tope del caso tipico. Si con datos reales (nombres largos con tildes, montos de
     * millones) los avisos no entran, LocalImportHelper::avisos_dentro_del_presupuesto() lo achica.
     *
     * @var int
     */
    const MAXIMO_DE_PROVEEDORES_EN_EL_AVISO = 50;

    /**
     * Filas cuyo saldo del Excel no se pudo leer como numero ("s/d", "-", una formula), como
     * [['fila' => n° de fila del Excel, 'nombre' => proveedor, 'texto' => lo que traia la celda], ...].
     *
     * Ese saldo no se cargo (LocalImportHelper::setSaldoInicial() devuelve 'ilegible'): el
     * proveedor se importo con el resto de sus datos. Se avisan al final, en un bloque aparte del
     * de saldos_no_cargados (mision importacion-saldo-celdas-de-texto, 9/10/2026). Va por FILA y no
     * por proveedor: lo que el usuario tiene que corregir es la celda.
     *
     * @var array
     */
    private $saldos_ilegibles = [];

    /**
     * Propiedades que esta fila vacia a proposito, como set [prop_key => true].
     *
     * Existe SOLO para isDataUpdated(): esa funcion pregunta por isset(), y isset() sobre
     * un null da false, asi que sin este registro un vaciado nunca dispararia el update()
     * y el checkbox no haria absolutamente nada. Se resetea en cada saveModel().
     *
     * @var array
     */
    private $props_vaciadas = [];

    /**
     * @param array       $columns
     * @param bool        $create_and_edit
     * @param int         $start_row
     * @param int|null    $finish_row
     * @param int|null    $provider_id
     * @param int         $hoja                     Indice 0-based de hoja. OPCIONAL: default 0.
     * @param string|null $hoja_nombre              Nombre de la hoja elegida. OPCIONAL: default null.
     * @param bool        $vaciar_valores_en_blanco Checkbox unico de la importacion. OPCIONAL: default false.
     */
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id, $hoja = 0, $hoja_nombre = null, $vaciar_valores_en_blanco = false) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->provider = null;
        $this->created_models = 0;
        $this->updated_models = 0;

        /*
         * Los tres ultimos parametros son OPCIONALES y con default a proposito:
         * AdminSync\AiExcelImportController construye esta clase con cinco argumentos y NO
         * se toca en esta mision. Un parametro nuevo sin default seria un
         * ArgumentCountError en el endpoint que usa admin-api contra clientes reales.
         */
        $this->hoja = (is_numeric($hoja) && (int) $hoja >= 0) ? (int) $hoja : 0;
        $this->hoja_nombre = (is_string($hoja_nombre) && trim($hoja_nombre) !== '')
                                ? trim($hoja_nombre)
                                : null;
        $this->vaciar_valores_en_blanco = filter_var($vaciar_valores_en_blanco, FILTER_VALIDATE_BOOLEAN);

        $this->setProps();
    }

    /**
     * Hoja (una sola) que Maatwebsite tiene que recorrer.
     *
     * 🔴 SIN este metodo, Maatwebsite NO importa la primera hoja: importa TODAS.
     * `Reader::loadSpreadsheet()` hace
     * `if (!$import instanceof WithMultipleSheets) { $this->sheetImports = array_fill(0, $this->spreadsheet->getSheetCount(), $import); }`,
     * o sea le aplica el MISMO mapeo de columnas a cada hoja del libro y llama
     * `collection()` una vez por hoja. Medido con un libro de tres hojas: tres llamadas.
     *
     * El sintoma: el usuario elige "Proveedores" en el selector del modal, ve el mapeo
     * armado sobre esa hoja, importa — y le quedaban proveedores creados con el texto de
     * la hoja de notas. El selector le prometia una decision que el backend ignoraba.
     *
     * ⚠️ CAMBIO DE COMPORTAMIENTO VISIBLE: antes se importaban todas las hojas del libro,
     * ahora se importa una sola (la 0 si nadie elige). Es lo que pide la mision: las hojas
     * no se combinan.
     *
     * El NOMBRE le gana al indice cuando viene, porque el indice lo calcula SheetJS en el
     * navegador y quien lee despues es otra libreria; los dos pueden discrepar.
     *
     * @return array
     */
    public function sheets(): array {
        if (!is_null($this->hoja_nombre)) {
            return [$this->hoja_nombre => $this];
        }

        return [$this->hoja => $this];
    }

    function setProps() {
        $this->props_to_set = [
            'num'               => 'numero',
            'name'              => 'nombre',
            'phone'             => 'telefono',
            'address'           => 'direccion',
            'location_id'       => 'localidad',
            'email'             => 'email',
            'razon_social'      => 'razon_social',
            'cuit'              => 'cuit',
            'observations'      => 'observaciones',
        ];
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
        foreach ($rows as $row) {
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    $provider = Provider::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $provider = $provider->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns));
                    } else {
                        $provider = $provider->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $provider = $provider->first();
                    $this->saveModel($row, $provider);
                }
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }

        $this->enviar_notificacion();
    }

    function enviar_notificacion() {
            
        $user = User::find(UserHelper::userId());

        $functions_to_execute = [
            [
                'btn_text'      => 'Actualizar lista de proveedores',
                'function_name' => 'update_provider_after_import',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Importacion de Excel finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $this->getInfoToShow(),
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    /**
     * Bloques informativos que se muestran al terminar la importacion. Mismo formato que
     * ClientImport::getInfoToShow() (`title` + `parrafos`), que el SPA ya renderiza.
     *
     * Dos bloques posibles, en este orden:
     *   1. Los saldos que no se cargaron porque la cuenta del proveedor ya tenia movimientos y
     *      otro saldo: lo arma bloque_de_saldos_no_cargados().
     *   2. Los saldos que no se pudieron leer (no son un numero): lo arma
     *      LocalImportHelper::bloque_de_saldos_ilegibles(), el mismo que usa ClientImport.
     *
     * 🔴 Los dos pasan por LocalImportHelper::avisos_dentro_del_presupuesto(): en el caso tipico se
     * nombran 50 y 20, pero si con datos reales (nombres largos con tildes, montos de millones) no
     * entran en el presupuesto de Pusher, se nombran menos (primero del bloque que mas pesa) y el
     * "y N ... mas" cuenta el resto. Con topes fijos solos, el evento pasaba los 10 KB y se perdia
     * la notificacion entera (9/10/2026).
     *
     * Si no hay nada que informar se devuelve un array vacio, igual que antes.
     *
     * @return array
     */
    function getInfoToShow() {
        $no_cargados = array_values($this->saldos_no_cargados);
        $ilegibles   = $this->saldos_ilegibles;

        return LocalImportHelper::avisos_dentro_del_presupuesto([], [
            'no_cargados' => [
                'tope'  => self::MAXIMO_DE_PROVEEDORES_EN_EL_AVISO,
                'total' => count($no_cargados),
                'armar' => function ($tope) use ($no_cargados) {
                    return $this->bloque_de_saldos_no_cargados($no_cargados, $tope);
                },
            ],
            'ilegibles' => [
                'tope'  => LocalImportHelper::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES,
                'total' => count($ilegibles),
                'armar' => function ($tope) use ($ilegibles) {
                    return LocalImportHelper::bloque_de_saldos_ilegibles($ilegibles, $tope);
                },
            ],
        ]);
    }

    /**
     * El bloque "Saldos del Excel que no se cargaron": un parrafo por proveedor con su nombre y los
     * dos montos (el del Excel y el de la cuenta, para que se vea la diferencia antes de ajustar),
     * como mucho $tope y, si hay mas, "y N proveedores mas" (nombrados + N = todos); la
     * explicacion va al final.
     *
     * El nombre se corta a LocalImportHelper::LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO caracteres (y se
     * sanea a UTF-8 valido), igual que en el bloque de ilegibles: es lo que mas pesa en el evento
     * de Pusher.
     *
     * @param  array $no_cargados Los de saldos_no_cargados, como lista.
     * @param  int   $tope        Cuantos nombrar (al menos uno).
     * @return array|null         El bloque, o null si no hay nada que avisar.
     */
    private function bloque_de_saldos_no_cargados(array $no_cargados, $tope) {
        if (count($no_cargados) == 0) {
            return null;
        }

        $tope = max(1, (int) $tope);

        $parrafos = [];

        foreach (array_slice($no_cargados, 0, $tope) as $no_cargado) {
            $parrafos[] = LocalImportHelper::recortar_para_el_aviso($no_cargado['nombre'], LocalImportHelper::LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO)
                . ': saldo en el Excel ' . $this->formatear_saldo($no_cargado['excel'])
                . ', saldo en la cuenta ' . $this->formatear_saldo($no_cargado['cuenta']);
        }

        $restantes = count($no_cargados) - $tope;

        if ($restantes > 0) {
            $parrafos[] = 'y ' . $restantes . ($restantes == 1 ? ' proveedor más' : ' proveedores más');
        }

        $parrafos[] = 'Estos proveedores ya tenían movimientos en su cuenta corriente y su saldo no coincide con el del Excel, '
            . 'así que el saldo del Excel no se cargó: el saldo inicial va solo en una cuenta vacía. '
            . 'Si el del Excel es el correcto, ajustá la cuenta con una nota de crédito o de débito por la diferencia.';

        return [
            'title'    => 'Saldos del Excel que no se cargaron',
            'parrafos' => $parrafos,
        ];
    }

    /**
     * Un saldo como lo lee un comerciante, con el mismo formato que Numbers::price() con signo:
     * "$99.999", "$7.600,50" (los centavos solo si los hay) y el menos adelante: "-$7.600".
     *
     * @param  float $saldo
     * @return string
     */
    private function formatear_saldo($saldo) {
        $saldo = round((float) $saldo, 2);

        $decimales = abs($saldo - round($saldo)) < 0.005 ? 0 : 2;

        return ($saldo < 0 ? '-' : '') . '$' . number_format(abs($saldo), $decimales, ',', '.');
    }

    function saveModel($row, $provider) {
        $existing_provider = !is_null($provider);
        $this->props_vaciadas = [];
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            $excel_value = ImportHelper::getColumnValue($row, $value, $this->columns);

            if (!is_null($excel_value)) {
                $data[$key] = $excel_value;
            } else if ($this->debeVaciar($value, $key, $existing_provider)) {
                $this->marcarVaciada($data, $key);
            }
        }
        $iva_aliases = [
            'condicion_frente_al_iva',
            'condicion frente al iva',
        ];

        $iva_condition_excel = ImportHelper::getColumnValueByAliases($row, $iva_aliases, $this->columns);

        if (!is_null($iva_condition_excel)) {
            $iva_condition_id = LocalImportHelper::getIvaConditionId($iva_condition_excel);

            if (!is_null($iva_condition_id)) {
                $data['iva_condition_id'] = $iva_condition_id;
            }
        } else if ($this->debeVaciarPorAliases($iva_aliases, 'iva_condition_id', $existing_provider)) {
            /*
             * providers.iva_condition_id es `int DEFAULT '0'` y acepta null: no hay FK
             * declarada contra iva_conditions, asi que null es "sin condicion asignada".
             */
            $this->marcarVaciada($data, 'iva_condition_id');
        }
        /*
         * OJO con la asimetria contra ClientImport: aca 'localidad' YA esta en
         * props_to_set (mapeada a location_id), asi que el vaciado lo resolvio el foreach
         * de arriba y este bloque no necesita un else. Si se le agregara uno, se marcaria
         * dos veces la misma propiedad.
         */
        if (!is_null(ImportHelper::getColumnValue($row, 'localidad', $this->columns))) {
            LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);
            $data['location_id'] = $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id');
        }
        // Log::info('data');
        // Log::info($data);
        if (!is_null($provider) && $this->isDataUpdated($provider, $data)) {
            Log::info('actualizando proveedor '.$provider->name);
            $provider->update($data);
        } else if (is_null($provider) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'numero', $this->columns);
            } else {
                $data['num'] = $this->ct->num('providers');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $provider = Provider::create($data);

            /*
             * El proveedor nace con sus dos cuentas corrientes, como en el ABM y como en
             * ClientImport. Sin esto el saldo del Excel no tenia donde cargarse (se perdia en
             * silencio) y una compra en cuenta corriente a este proveedor reventaba con un 500
             * (mision importacion-proveedores-saldo-inicial, 8/10/2026).
             *
             * La cuenta lleva el user_id del propio proveedor: asi queda siempre del mismo
             * comercio que el proveedor, venga de donde venga la llamada. (Hoy da lo mismo que
             * la sesion, porque $data['user_id'] sale de $this->ct->userId().)
             */
            CreditAccountHelper::crear_credit_accounts('provider', $provider->id, $provider->user_id);

            Log::info('se creo proveedor '.$provider->name.' con la data: ');
            Log::info($data);
        }

        /*
         * Sin proveedor no hay saldo que cargar: es una fila de "solo editar" (create_and_edit
         * apagado) cuyo proveedor no existe. Antes llegaba igual al helper y reventaba con un
         * 500 a mitad del archivo.
         */
        if (!is_null($provider)) {
            $saldos = null;

            $estado_saldo = LocalImportHelper::setSaldoInicial($row, $this->columns, 'provider', $provider, $saldos);

            /*
             * Solo se avisa cuando la cuenta tiene OTRO saldo. 'sin_cambios' (la cuenta ya tiene
             * el saldo del Excel: la segunda pasada del mismo archivo, o un proveedor repetido
             * con el mismo saldo) no se avisa: el aviso diria "ajusta con una nota" y seguirlo
             * duplicaria la deuda.
             */
            if ($estado_saldo == 'ya_tenia_movimientos') {
                $this->saldos_no_cargados[$provider->id] = [
                    'nombre' => $provider->name,
                    'excel'  => $saldos['excel'],
                    'cuenta' => $saldos['cuenta'],
                ];
            }

            /*
             * Un saldo que no es un numero no se cargo: se anota la fila para el aviso de fin.
             * num_row es el numero de fila del Excel: collection() cuenta desde la fila 1 de la
             * hoja (la cabecera incluida).
             */
            if ($estado_saldo == 'ilegible') {
                $this->saldos_ilegibles[] = [
                    'fila'   => $this->num_row,
                    'nombre' => $provider->name,
                    'texto'  => $saldos['texto'],
                ];
            }
        }
    }

    /**
     * Propiedades que NUNCA se vacian, aunque el checkbox este prendido.
     *
     *   name -> `providers.name` es NOT NULL en la base. Un update con name = null es un
     *           error de SQL. Ademas es inalcanzable: checkRow() saltea la fila cuando
     *           "nombre" viene vacio.
     *   num  -> es el IDENTIFICADOR con el que collection() busca el proveedor. Vaciarlo
     *           le borra el numero al proveedor y lo vuelve imposible de matchear en la
     *           proxima importacion. Es la diferencia con ClientImport, donde 'numero' se
     *           usa para matchear pero no esta en props_to_set.
     *
     * @return array
     */
    private function propsQueNuncaSeVacian() {
        return ['name', 'num'];
    }

    /**
     * Anota en $data que esta propiedad se vacia, y la registra para isDataUpdated().
     *
     * @param  array  $data  Se modifica por referencia
     * @param  string $prop_key
     * @return void
     */
    private function marcarVaciada(&$data, $prop_key) {
        $data[$prop_key] = null;
        $this->props_vaciadas[$prop_key] = true;
    }

    /**
     * ¿Hay que escribir vacio en $prop_key porque la celda de $column_key vino vacia?
     *
     * Las tres condiciones, y las tres importan:
     *   1. El checkbox de la importacion esta prendido.
     *   2. El proveedor YA EXISTE. Al crear no hay nada que vaciar.
     *   3. La columna esta MAPEADA. Este es el punto caro: si el Excel trae 4 columnas y el
     *      sistema tiene 9 propiedades, prender el checkbox no puede vaciar las otras 5.
     *      getColumnValue() devuelve null tanto para "columna sin mapear" como para "celda
     *      vacia", asi que sin isIgnoredColumn() los dos casos serian indistinguibles.
     *
     * @param  string $column_key  Clave de la columna en el mapeo (ej: 'telefono')
     * @param  string $prop_key    Propiedad del sistema (ej: 'phone')
     * @param  bool   $existing    Si el modelo ya existia
     * @return bool
     */
    private function debeVaciar($column_key, $prop_key, $existing) {
        return $this->debeVaciarPorAliases([$column_key], $prop_key, $existing);
    }

    /**
     * Igual que debeVaciar() pero para las columnas que se mapean con varios alias
     * (condicion frente al iva). Alcanza con que UNO este mapeado.
     *
     * @param  array  $column_keys
     * @param  string $prop_key
     * @param  bool   $existing
     * @return bool
     */
    private function debeVaciarPorAliases(array $column_keys, $prop_key, $existing) {
        if (!$this->vaciar_valores_en_blanco || !$existing) {
            return false;
        }

        if (in_array($prop_key, $this->propsQueNuncaSeVacian(), true)) {
            return false;
        }

        foreach ($column_keys as $column_key) {
            if (!ImportHelper::isIgnoredColumn($column_key, $this->columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Alguno de los vaciados de esta fila cambia algo de verdad?
     *
     * Va aparte de la lista de isset() de abajo porque isset() sobre null da false: sin
     * esto, un vaciado nunca dispararia el update() y el checkbox seria decorativo.
     *
     * @param  \App\Models\Provider $provider
     * @return bool
     */
    private function hayVaciadoEfectivo($provider) {
        foreach ($this->props_vaciadas as $prop_key => $marcada) {
            $valor_actual = $provider->{$prop_key};

            if (!is_null($valor_actual) && $valor_actual !== '') {
                return true;
            }
        }

        return false;
    }

    function isDataUpdated($provider, $data) {
        if ($this->hayVaciadoEfectivo($provider)) {
            return true;
        }

        return  (isset($data['name']) && $data['name']                              != $provider->name) ||
                (isset($data['phone']) && $data['phone']                            != $provider->phone) ||
                (isset($data['address']) && $data['address']                        != $provider->address) ||
                (isset($data['email']) && $data['email']                            != $provider->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $provider->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $provider->cuit) ||
                (isset($data['observations']) && $data['observations']                != $provider->observations) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $provider->iva_condition_id) ||
                (isset($data['location_id']) && $data['location_id']                != $provider->location_id);
    }
}
