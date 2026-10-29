<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use App\Models\AiConversation;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de la suite de la app «ComercioCity IA» (misión app-ia-y-memoria, 29/10/2026).
 *
 * Arma un dueño con documento y contraseña, con la extensión agentes_ia, y un token de la app REAL
 * (createToken con la habilidad `ia-app`, el mismo que emite admin-sync/ia-app/token). Habla con el
 * API como la app: `Authorization: Bearer`, sin cookie.
 *
 * 🔴 ACÁ NO HAY actingAs() en los tests con Bearer (misma regla que McpTestCase): después de un
 * actingAs('web') Sanctum autentica también al Bearer por el guard web, y un "este token no entra"
 * pasaría por el motivo equivocado. Y entre dos pedidos con tokens distintos del mismo test va
 * como_otro_cliente(): el RequestGuard de Sanctum cachea al usuario que resolvió.
 *
 * 🔴 Ningún test sale a la red: la clave de Anthropic queda null, la de DeepSeek de prueba solo en los
 * tests que corren el loop (con Http::fake), y la de OpenAI la pone cada test que transcribe.
 */
abstract class IaAppTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo IA. */
    const SLUG = 'agentes_ia';

    /** La clave de admin-sync de este cliente en los tests. */
    const CLAVE_ADMIN = 'clave-admin-de-prueba-ia-app';

    /** Documento y contraseña del dueño. */
    const DOCUMENTO = '30111222';

    const CLAVE = 'la-clave-del-duenio';

    /** @var \App\Models\User */
    protected $dueno;

    /** @var string El token de la app del dueño, en texto plano. */
    protected $token;

    /** @var array<int, string> Carpetas del disco local a borrar al terminar. */
    protected $carpetas_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'    => null,
            'services.deepseek.api_key'     => null,
            'services.admin_api.api_key'    => self::CLAVE_ADMIN,
            'services.admin_api.url'        => 'https://admin.ia-app.test',
            'services.admin_api.inbound_key' => 'clave-inbound-de-prueba',
            'services.admin_api.client_uuid' => 'uuid-del-cliente-de-prueba',
            /*
             * 🔴 La instancia atiende a ESTE dueño: la base de testing tiene muchos dueños de otros
             * tests y sin USER_ID AsistenteCanalHelper::dueno() no podría decidir.
             */
            'app.USER_ID'                   => null,
        ]);

        $this->dueno = User::create([
            'name'         => 'Dueño de la app',
            'company_name' => 'Ferretería de la app ' . uniqid(),
            'email'        => 'ia-app-' . uniqid() . '@test.local',
            'doc_number'   => self::DOCUMENTO . substr(uniqid(), -5),
            'password'     => Hash::make(self::CLAVE),
        ]);

        config(['app.USER_ID' => $this->dueno->id]);

        $this->dar_extension($this->dueno);

        $this->token = IaAppHelper::emitir_token($this->dueno, 'Pixel de prueba');
    }

    protected function tearDown(): void
    {
        foreach ($this->carpetas_a_limpiar as $carpeta) {
            Storage::disk('local')->deleteDirectory($carpeta);
        }

        $this->carpetas_a_limpiar = [];

        parent::tearDown();
    }

    /**
     * Asigna la extensión a un dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return void
     */
    protected function dar_extension(User $dueno)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Agentes de IA']);
        }

        $dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $dueno->load('extencions');
    }

    /**
     * Un empleado del dueño, con documento y contraseña.
     *
     * @param  int  $admin_access
     * @return \App\Models\User
     */
    protected function empleado($admin_access = 0)
    {
        return User::create([
            'name'         => 'Empleado de la app',
            'email'        => 'ia-app-empleado-' . uniqid() . '@test.local',
            'doc_number'   => '40' . substr(uniqid(), -6),
            'password'     => Hash::make('clave-del-empleado'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => $admin_access,
        ]);
    }

    /**
     * Los headers de la app.
     *
     * @param  string|null  $token  Default: el token del dueño.
     * @return array<string, string>
     */
    protected function de_la_app($token = null)
    {
        return [
            'Authorization' => 'Bearer ' . (is_null($token) ? $this->token : $token),
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Los headers de admin-sync con la clave del cliente.
     *
     * @param  string|null  $clave
     * @return array<string, string>
     */
    protected function del_admin($clave = self::CLAVE_ADMIN)
    {
        return ['X-Admin-Api-Key' => (string) $clave, 'Accept' => 'application/json'];
    }

    /**
     * Simula que el PRÓXIMO pedido lo hace otro proceso (ver el docblock de la clase).
     *
     * @return void
     */
    protected function como_otro_cliente()
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Una conversación del dueño.
     *
     * @param  array  $extra
     * @return \App\Models\AiConversation
     */
    protected function conversacion(array $extra = [])
    {
        return AiConversation::create(array_merge([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ], $extra));
    }

    /**
     * Una carpeta del disco local que el test va a escribir y hay que borrar al final.
     *
     * @param  string  $carpeta
     * @return void
     */
    protected function limpiar_al_final($carpeta)
    {
        $this->carpetas_a_limpiar[] = $carpeta;
    }
}
