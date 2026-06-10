<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\VersionSessionTransferHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Modo de login maestro detectado en el request actual.
     * Valores posibles: null | 'login' | 'login_full'.
     *
     * @var string|null
     */
    protected $master_login_mode = null;

    /**
     * Clave de sesión para indicar que el login actual es maestro (loginLucas)
     * y no debe tomar el lock de sesión única por last_activity/session_id.
     *
     * @var string
     */
    protected $master_login_bypass_activity_key = 'master_login_bypass_user_last_activity';
    
    function login(Request $request) {
        $login = false;
        $user = null;
        $user_last_activity = false;
        $user_last_activity_wait_minutes = 0;
        /**
         * Determina si en este login se debe omitir la sincronización offline.
         */
        $skip_offline_articles_sync = false;

        if ($this->loginLucas($request)) {
            /**
             * Marca la sesión actual como login maestro para evitar
             * ocupar el lock de sesión única del usuario.
             */
            session()->put($this->master_login_bypass_activity_key, true);

            $user = $this->procesar_login();
            /** Solo en login maestro básico se omite descarga offline. */
            $skip_offline_articles_sync = $this->master_login_mode === 'login';
            
            $login = true;
        } else if (Auth::attempt(['doc_number' => $request->doc_number, 
                           'password' => $request->password], $request->remember)) {
            /** Limpia bypass en logins normales. */
            session()->forget($this->master_login_bypass_activity_key);
            
            if ($this->checkUserLastActivity()) {

                $user = $this->procesar_login();

                $login = true;

                Log::info("Usuario {$user->name}, doc: {$user->doc_number} entro desde: ".$request->header('referer'));
            } else {
                $user_last_activity_wait_minutes = $this->getUserLastActivityWaitMinutes(Auth()->user());
                Auth::logout();
                Log::info('no paso user_last_activity');
                $user_last_activity = true;
            }
        } 

        /**
         * Persiste en sesión si hay que omitir sincronización offline en esta sesión.
         */
        session()->put('skip_offline_articles_sync', $skip_offline_articles_sync);

        if ($user) {
            $user->skip_offline_articles_sync = $skip_offline_articles_sync;
            $user->master_login_mode = $this->master_login_mode;
        }

        return response()->json([
            'login'                 => $login,
            'user'                  => $user,
            'user_last_activity'    => $user_last_activity,
            'user_last_activity_wait_minutes' => $user_last_activity_wait_minutes,
        ], 200);
    }

    function procesar_login() {

        $user = $this->get_auth_user();

        $this->set_login_at($user);
        
        $user = $this->set_employee_props($user);

        UserHelper::set_sessions($user);

        return $user;
    }


    function set_employee_props($user) {
        if ($user->owner_id) {
            $owner = User::where('id', $user->owner_id)
                            ->withAll()
                            ->first();

            $user->owner_extencions = $owner->extencions;
            $user->owner_configuration = $owner->configuration;
            $user->iva_included = $owner->iva_included;
            $user->ask_amount_in_vender = $owner->ask_amount_in_vender;
            $user->owner = $owner;
            $user->owner->extencions = $owner->extencions;
            // Log::info('set_employee_props para '.$user->name);
            // Log::info('owner_extencions: ');
            // Log::info($user->owner_extencions);
        }
        return $user;
    }

    function get_auth_user() {
        $user = Auth()->user();
        if ($user) {
            return User::where('id', $user->id)
                        ->withAll()
                        ->first();
        }
        return null;
    }

    function set_login_at($user) {
        $user->login_at = Carbon::now();
        $user->save();
        Log::info('se puso login a '.$user->name.' a las '.$user->login_at->format('d/m/y H'));
    }

    function set_logout_at($user_id) {
        $user = User::find($user_id);
        $user->logout_at = Carbon::now();
        $user->save();
    }

    // function set_sessions($auth_user) {



    //     // Convertimos el user a array seguro (solo lo necesario)

    //     $user_data = (object) $auth_user->attributesToArray();
    //     $user_data->permissions    = $auth_user->permissions;

    //     // Hacemos lo mismo con el owner
    //     $owner = UserHelper::getFullModel();

    //     $owner_data = (object) $owner->attributesToArray();
    //     $owner_data->extencions    = $owner->extencions;

    //     // Log::info('Session ID before: ', session()->all());

    //     session()->put('auth_user', $user_data);
    //     session()->put('owner', $owner_data);

    //     // session([
    //     //     'auth_user' => $user_data,
    //     //     'owner'     => $owner_data,
    //     // ]);

    //     // Log::info('Session ID after: ', session()->all());

    //     // Log::info('set_sessions auth_user:');
    //     // Log::info($auth_user);
    //     // session(['auth_user' => $auth_user, 'owner' => UserHelper::getFullModel()]);
    // }

    /**
     * Crea un token de un solo uso para iniciar sesión en la versión SPA/API destino
     * tras un login válido en otra versión (misma base de datos).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create_version_session_token(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['token' => null], 401);
        }

        /** Usuario autenticado en la versión origen. */
        $auth_user = Auth::user();
        $plain_token = VersionSessionTransferHelper::create_for_user($auth_user->id);

        return response()->json(['token' => $plain_token], 200);
    }

    /**
     * Consume un token de transferencia e inicia sesión web en la API destino.
     *
     * @param Request $request Debe incluir `token` (string).
     * @return \Illuminate\Http\JsonResponse Misma forma que `login`.
     */
    public function login_from_version_session_token(Request $request)
    {
        $login = false;
        $user = null;
        $user_last_activity = false;
        $user_last_activity_wait_minutes = 0;

        /** Token enviado por el SPA destino desde el query string. */
        $plain_token = trim((string) $request->input('token', ''));

        /** Id de usuario asociado al token, o null si expiró o ya se usó. */
        $user_id = VersionSessionTransferHelper::consume($plain_token);

        if ($user_id) {
            /** Modelo a autenticar en esta API. */
            $candidate = User::find($user_id);

            if ($candidate) {
                session()->forget($this->master_login_bypass_activity_key);
                session()->forget('skip_offline_articles_sync');

                Auth::login($candidate, false);

                if ($this->checkUserLastActivity()) {
                    $user = $this->procesar_login();
                    $login = true;
                    Log::info(
                        'Login por transferencia de version para user_id: '.$user_id
                        .' desde referer: '.$request->header('referer')
                    );
                } else {
                    $user_last_activity_wait_minutes = $this->getUserLastActivityWaitMinutes(Auth::user());
                    Auth::logout();
                    $user_last_activity = true;
                }
            }
        }

        return response()->json([
            'login' => $login,
            'user' => $user,
            'user_last_activity' => $user_last_activity,
            'user_last_activity_wait_minutes' => $user_last_activity_wait_minutes,
        ], 200);
    }

    public function logout(Request $request) {
        /**
         * Libera el lock de sesión única (last_activity) para que otro
         * dispositivo pueda iniciar sesión. Aplica también tras login maestro:
         * el ingreso maestro no ocupa el lock, pero al cerrar sesión se
         * fuerza la ventana de actividad como vencida en AuthHelper.
         */
        if (Auth::check()) {
            $this->removeUserLastActivity();
        }
        /** Limpia flag del bypass para evitar arrastre entre sesiones. */
        session()->forget($this->master_login_bypass_activity_key);
        /** Limpia flag de sesión para próximos inicios de sesión. */
        session()->forget('skip_offline_articles_sync');

        $user = UserHelper::getFullModel(false);
        
        $this->set_logout_at($user->id);

        Auth::logout();
        return response(null, 200);
    }

    public function get_user() {
        /**
         * En login maestro se evita escribir last_activity/session_id para que
         * el acceso de soporte no ocupe la sesión única del usuario.
         */
        if ($this->is_master_login_activity_bypass_enabled()) {
            $user = UserHelper::getFullModel(false);
            $user = $this->set_employee_props($user);
            $user->skip_offline_articles_sync = (bool) session('skip_offline_articles_sync', false);
            UserHelper::set_sessions($user);
            return response()->json(['user' => $user], 200);
        }

        if ($this->checkUserLastActivity()) {
            // $user = UserHelper::user(false);
            $user = UserHelper::getFullModel(false);
            $user = $this->set_employee_props($user);
            /**
             * Reinyecta el flag de sesión para que el frontend mantenga el comportamiento.
             */
            $user->skip_offline_articles_sync = (bool) session('skip_offline_articles_sync', false);
            UserHelper::set_sessions($user);
            return response()->json(['user' => $user], 200);
        }
        return response()->json(['user' => null], 403);
    }

    /**
     * Informa si la sesión actual está en modo bypass de lock de actividad.
     *
     * @return bool
     */
    function is_master_login_activity_bypass_enabled() {
        return (bool) session($this->master_login_bypass_activity_key, false);
    }

    public function loginLucas($request) {
        /** Normaliza el valor ingresado en doc_number para detectar comandos maestros. */
        $doc_number = trim((string) $request->doc_number);
        $doc_number_lower = strtolower($doc_number);

        /** Guarda el texto previo al comando para intentar login por documento. */
        $prefixed_doc_number = null;
        $is_login_command = false;
        $is_login_full_command = false;

        /**
         * Compatibilidad:
         * - "login"
         * - "login full"
         * - "<cualquier texto> login"
         * - "<cualquier texto> login full"
         */
        if (
            $doc_number_lower === 'login'
            || substr($doc_number_lower, -6) === ' login'
        ) {
            $is_login_command = true;
            /** Extrae el posible documento escrito antes de "login". */
            if ($doc_number_lower !== 'login') {
                $prefixed_doc_number = trim(substr($doc_number, 0, -6));
            }
        }

        if (
            $doc_number_lower === 'login full'
            || substr($doc_number_lower, -11) === ' login full'
        ) {
            $is_login_full_command = true;
            /** Extrae el posible documento escrito antes de "login full". */
            if ($doc_number_lower !== 'login full') {
                $prefixed_doc_number = trim(substr($doc_number, 0, -11));
            }
        }

        Log::info('is_login_command = '.$is_login_command);
        Log::info('is_login_full_command = '.$is_login_full_command);
        Log::info('loginLucas documento detectado: '.$doc_number_lower);

        if ($is_login_command || $is_login_full_command) {
            /** Define modo para usarlo luego en login() y respuesta al frontend. */
            if ($is_login_full_command) {
                $this->master_login_mode = 'login_full';
            } else {
                $this->master_login_mode = 'login';
            }

            /**
             * Si hay texto previo al comando, lo interpreta como doc_number preferido.
             * Si no existe usuario para ese documento, mantiene el fallback histórico.
             */
            $user = null;
            if (!empty($prefixed_doc_number)) {
                $user = User::where('doc_number', $prefixed_doc_number)->first();
            }

            if (!$user) {
                $user = User::whereNull('owner_id')->first();
            }

            /** Evita errores cuando no hay usuarios aptos para login maestro. */
            if (!$user) {
                Log::warning('loginLucas sin usuario candidato para autenticar.');
                return false;
            }
                            
            $user->prev_password = $user->password;
            $user->password = bcrypt('1234');
            $user->save();
            if (Auth::attempt(['doc_number' => $user->doc_number, 
                                'password' => '1234'])) {
                
                Log::info('Lucas logeo el user '.$user->name.', doc_number: '.$user->doc_number);
                
                $user->password = $user->prev_password;
                $user->save();
                // $user = UserHelper::getFullModel(false);
                
                Log::info('user name: '.$user->name);
                
                return true;
            }
        } else {
            Log::info('No entro');
        }
        return false;
    }

    function checkUserLastActivity() {
        if (class_exists('App\Http\Controllers\Helpers\AuthHelper')) {
            $auth_helper = new \App\Http\Controllers\Helpers\AuthHelper();
            if (method_exists($auth_helper, 'checkUserLastActivity')) {
                return $auth_helper->checkUserLastActivity();
            }
        }
        return true;
    }

    function removeUserLastActivity() {
        if (class_exists('App\Http\Controllers\Helpers\AuthHelper')) {
            $auth_helper = new \App\Http\Controllers\Helpers\AuthHelper();
            if (method_exists($auth_helper, 'removeUserLastActivity')) {
                return $auth_helper->removeUserLastActivity(Auth()->user());
            }
        } 
    }

    function getUserLastActivityWaitMinutes($user = null) {
        if (!$user) {
            return 0;
        }

        if (class_exists('App\Http\Controllers\Helpers\AuthHelper')) {
            $auth_helper = new \App\Http\Controllers\Helpers\AuthHelper();
            if (method_exists($auth_helper, 'get_remaining_wait_minutes')) {
                return $auth_helper->get_remaining_wait_minutes($user);
            }
        }

        return 0;
    }

}