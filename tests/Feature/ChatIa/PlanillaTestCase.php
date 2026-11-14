<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaDeTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\AiPlanilla;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\EmpresaTestCase;

/**
 * Lo que comparten los tests de las planillas de ventas del asistente (misión agente-venta-por-planilla,
 * 02/11/2026): el dueño del fixture de plata en "cauteloso", cuatro artículos propios con su código, una
 * planilla de prueba con datos inventados, imágenes generadas y un proveedor falso que enruta por modelo.
 *
 * 🔴 Todos los nombres, documentos, códigos y operaciones son inventados.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
abstract class PlanillaTestCase extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** Los CUIT de la planilla de prueba: inventados, con su dígito verificador bien. */
    const CUIT_EMPRESA = '30712345671';

    /** Mismos diez primeros dígitos, con el verificador mal: lo que hizo el modelo en el caso real. */
    const CUIT_MAL = '30712345670';

    /** Un pedido del dueño que dice todo lo que definir_planilla necesita anclar a un mensaje suyo. */
    const PEDIDO_PARA_CARGAR = 'Cargá esas ventas: es el total y se cobraron en Efectivo, Caja Efectivo';

    /** Lo que el proveedor falso contesta a las lecturas, por modelo: [modelo => respuesta o lista de respuestas]. */
    protected $lecturas = [];

    /** Lo que contesta el clasificador: true, false o null (falla). */
    protected $clasificador = true;

    /** Las vueltas del modelo que conversa, en orden. */
    protected $vueltas = [];

    /** true si el proveedor falso ya está puesto. */
    protected $ia_falseada = false;

    /** Lo que contesta Claude al analizar una foto común; null si falla. */
    protected $analisis_de_la_foto = 'FOTO 1: Un martillo galponero marca Acme, con mango de madera.';

    /** Lo que contesta Claude al volver a mirar una foto. */
    protected $respuesta_de_la_foto = 'El lote dice L-204.';

    /** La transcripción temporal de respaldo usa el Haiku fijo de develop; null simula su falla. */
    protected $transcripcion_de_respaldo = 'FOTO 1: Lectura transitoria con Haiku, que no se guarda.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
            'services.anthropic.api_key'        => 'clave-de-prueba',
            'services.anthropic.model'          => 'claude-general-c107',
            'services.anthropic.model_agil'     => 'claude-agil-c107',
            'services.anthropic.model_profundo' => 'claude-profundo-c107',
            'services.deepseek.api_key'         => 'clave-deepseek-c107',
            'services.deepseek.model'           => 'deepseek-general-c107',
            'services.deepseek.model_agil'      => 'deepseek-flash-c107',
            'services.deepseek.model_profundo'  => 'deepseek-pro-c107',
            'services.deepseek.model_vision'    => 'deepseek-vision-c107',
            'services.lectura_planilla.activa'  => true,
        ]);

        Storage::fake('local');

        Cache::flush();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::CAUTELOSO;
        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();
        $this->dueno->load('extencions');

        // Una base ya sembrada con estos códigos no tiene que dar «varios artículos».
        foreach (['70101', '70102', 'ZRRI-50LF', 'wzz3324'] as $codigo) {
            Article::where('user_id', $this->dueno->id)->where(function ($q) use ($codigo) {
                $q->where('bar_code', $codigo)->orWhere('provider_code', $codigo);
            })->delete();
        }

        $this->articulo_de_prueba('zz-c107 Bomba Alfa', ['bar_code' => '70101']);
        $this->articulo_de_prueba('zz-c107 Bomba Beta', ['bar_code' => '70102']);
        $this->articulo_de_prueba('zz-c107 Foco', ['provider_code' => 'ZRRI-50LF']);
        $this->articulo_de_prueba('zz-c107 Maletin herramientas', ['provider_code' => 'wzz3324']);
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Un artículo del dueño. El precio de lista es a propósito distinto del de la planilla: la venta tiene que
     * salir con el de la planilla.
     *
     * @param  string  $nombre
     * @param  array  $extra
     * @return Article
     */
    protected function articulo_de_prueba($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 11111,
            'cost'        => 5000,
            'stock'       => 100,
            'iva_id'      => 2,
        ], $extra));
    }

    /**
     * Las filas de la planilla de prueba, tal como salen del Excel: la columna del comprobante, el importe, la
     * fecha, el código, la descripción, la operación, la cantidad, el tipo y número de documento, el nombre y la
     * dirección. Todo inventado.
     *
     * @return array
     */
    protected function filas_de_prueba()
    {
        return [
            ['C FINAL', '61.250,00', '13-oct', 'SKU 70101', 'Bomba Presurizadora Alfa', '#2000000000000001', '1', 'DNI', '17000001', 'Ana Prueba Uno', 'Calle Falsa, 100 - Ciudad Uno - CP: 1000'],
            ['C FINAL', '88.400,00', '13-oct', 'SKU 70102', 'Bomba Presurizadora Beta', '#2000000000000002', '2', 'DNI', '14000002', 'Bruno Prueba Dos', 'Calle Falsa, 200 - Ciudad Dos - CP: 2000'],
            ['FACTURA A', '204.000,00', '19-oct', 'SKU ZRRI-50LF', 'Foco Led 30w', '#2000000000000003', '2', 'CUIT', self::CUIT_EMPRESA, 'Empresa Prueba SA', ''],
            ['C FINAL', '73.500,00', '22-oct', 'wzz3324', 'Maletin Para Herramientas', '#2000000000000004', '1', 'DNI', '13000004', 'Carla Prueba Tres', 'Calle Falsa, 400 - Ciudad Tres - CP: 4000'],
        ];
    }

    /**
     * La planilla de prueba como un texto pegado del Excel.
     *
     * @return string
     */
    protected function texto_pegado()
    {
        $lineas = [];

        foreach ($this->filas_de_prueba() as $fila) {
            $lineas[] = implode("\t", $fila);
        }

        return implode("\n", $lineas);
    }

    /**
     * Lo que contesta una lectura.
     *
     * @param  array  $filas
     * @param  array|null  $encabezado
     * @return array
     */
    protected function lectura($filas, $encabezado = null)
    {
        return $this->texto_del_modelo(json_encode(['encabezado' => $encabezado, 'filas' => $filas], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  string  $texto
     * @param  string  $stop_reason
     * @return array
     */
    protected function texto_del_modelo($texto, $stop_reason = 'end_turn')
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    /**
     * Una vuelta del modelo que conversa pidiendo una herramienta.
     *
     * @param  string  $nombre
     * @param  array  $input
     * @return array
     */
    protected function pide_herramienta($nombre, array $input = [])
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [['type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $nombre, 'input' => (object) $input]],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    /**
     * Falsea los dos proveedores, enrutando por lo que se pide y no por el orden: cada test dice qué contesta cada
     * lectura y cada vuelta del modelo que conversa, y lo demás no importa.
     *
     * @return void
     */
    protected function falsear_ia()
    {
        /*
         * Una sola vez: Http::fake() acumula, y en cada pedido corre TODAS las que se registraron y se queda con la primera respuesta. Con
         * dos, una lista de respuestas de $this->lecturas se consumiría de a dos por llamada.
         */
        if ($this->ia_falseada) {

            return;
        }

        $this->ia_falseada = true;

        Http::fake(function ($request) {

            $cuerpo = json_decode($request->body(), true);

            $ultimo = is_array($cuerpo) && isset($cuerpo['messages']) ? end($cuerpo['messages']) : [];

            $texto = json_encode(isset($ultimo['content']) ? $ultimo['content'] : '', JSON_UNESCAPED_UNICODE);

            /* Las llamadas de las fotos en texto llevan su `system` como un string; el modelo que conversa, como bloques. */
            $system = is_array($cuerpo) && isset($cuerpo['system']) && is_string($cuerpo['system']) ? $cuerpo['system'] : '';

            if (mb_strpos($system, 'Sos un transcriptor de fotos') !== false) {

                /*
                 * Ambos caminos llaman a Anthropic con el mismo prompt: la URL ya no los distingue.
                 * El respaldo usa el Haiku fijo de develop; el análisis persistente lleva su modelo configurable.
                 */
                if (($cuerpo['model'] ?? '') === \App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper::MODELO_HAIKU_IMAGENES) {

                    if (is_null($this->transcripcion_de_respaldo)) {
                        return Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'no']], 529);
                    }

                    return Http::response($this->texto_del_modelo($this->transcripcion_de_respaldo), 200);
                }

                if (is_null($this->analisis_de_la_foto)) {
                    return Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'no']], 529);
                }

                return Http::response($this->texto_del_modelo($this->analisis_de_la_foto), 200);
            }

            if (mb_strpos($system, 'contestá SOLO esta pregunta') !== false) {
                return Http::response($this->texto_del_modelo($this->respuesta_de_la_foto), 200);
            }

            if (mb_strpos($texto, 'es_planilla') !== false) {

                if (is_null($this->clasificador)) {
                    return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'no']], 400);
                }

                return Http::response($this->texto_del_modelo(json_encode(['es_planilla' => $this->clasificador])), 200);
            }

            if (mb_strpos($texto, 'Transcribí TODAS las filas') !== false) {

                $modelo = (string) $cuerpo['model'];

                $respuesta = isset($this->lecturas[$modelo]) ? $this->lecturas[$modelo] : null;

                /* Una lista: una respuesta por llamada, en orden. Agotada, es como si no hubiera. */
                if (is_array($respuesta) && (isset($respuesta[0]) || $respuesta === [])) {
                    $respuesta = array_shift($this->lecturas[$modelo]);
                }

                if (is_null($respuesta)) {
                    return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'sin stub'], 'type' => 'error'], 400);
                }

                if (isset($respuesta['http_status'])) {
                    return Http::response($respuesta['cuerpo'], $respuesta['http_status']);
                }

                return Http::response($respuesta, 200);
            }

            $vuelta = count($this->vueltas) ? array_shift($this->vueltas) : $this->texto_del_modelo('Texto del modelo que conversa.');

            return Http::response($vuelta, 200);
        });
    }

    /**
     * Los tres lectores contestan lo mismo.
     *
     * @param  array  $filas
     * @param  array|null  $encabezado
     * @return void
     */
    protected function todos_leen($filas, $encabezado = null)
    {
        foreach (['claude-opus-5-5', 'claude-haiku-5-5', 'claude-sonnet-5-5'] as $modelo) {
            $this->lecturas[$modelo] = $this->lectura($filas, $encabezado);
        }
    }

    /**
     * Una conversación del dueño.
     *
     * @return AiConversation
     */
    protected function conversacion()
    {
        return AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
    }

    /**
     * Un mensaje del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $contenido
     * @param  string  $canal
     * @return AiMessage
     */
    protected function del_dueno(AiConversation $conversation, $contenido, $canal = AiMessage::CANAL_SISTEMA)
    {
        return AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'estado'             => 'listo',
            'contenido'          => $contenido,
            'canal'              => $canal,
        ]);
    }

    /**
     * El assistant que se está generando.
     *
     * @param  AiConversation  $conversation
     * @param  string  $canal
     * @return AiMessage
     */
    protected function assistant(AiConversation $conversation, $canal = AiMessage::CANAL_SISTEMA)
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
            'canal'                => $canal,
        ]);
    }

    /**
     * Cuelga una foto generada de un mensaje. Es un lienzo: lo que «dice» lo contesta el proveedor falso.
     *
     * @param  AiMessage  $mensaje
     * @param  int  $ancho
     * @param  int  $alto
     * @param  int  $orden
     * @return AiMessageImagen
     */
    protected function foto(AiMessage $mensaje, $ancho = 1500, $alto = 200, $orden = 1)
    {
        $binario = (string) (new ImageManager())->canvas($ancho, $alto, '#ffffff')->encode('png');

        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $mensaje->id . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->dueno->id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Un turno entero con el servicio de verdad.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @return string
     */
    protected function responder(AiConversation $conversation, AiMessage $assistant)
    {
        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $assistant->contenido = $texto;
        $assistant->estado = 'listo';
        $assistant->save();

        return $texto;
    }

    /**
     * El mensaje con la planilla pegada, ya procesado: deja la planilla guardada sin pasar por el modelo.
     *
     * @param  AiConversation  $conversation
     * @param  string|null  $texto
     * @return AiPlanilla
     */
    protected function planilla_pegada(AiConversation $conversation, $texto = null)
    {
        $this->del_dueno($conversation, is_null($texto) ? $this->texto_pegado() : $texto);

        $assistant = $this->assistant($conversation);

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);

        $assistant->contenido = 'Leí la planilla.';
        $assistant->estado = 'listo';
        $assistant->save();

        return AiPlanilla::where('ai_conversation_id', $conversation->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * Una herramienta de carga, como la llama el modelo.
     *
     * @param  string  $nombre
     * @param  array  $input
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @return array
     */
    protected function herramienta($nombre, array $input, AiConversation $conversation, AiMessage $assistant)
    {
        $respuesta = HerramientasDeCarga::ejecutar($nombre, $input, $conversation, $assistant);

        return json_decode($respuesta['content'], true);
    }

    /**
     * Deja la planilla lista para proponer: el importe es el total y se cobró en efectivo.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @return void
     */
    protected function definir_para_cargar(AiConversation $conversation, AiMessage $assistant)
    {
        $definida = $this->herramienta('definir_planilla', [
            'importe_es'     => 'total',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ], $conversation, $assistant);

        $this->assertTrue(!empty($definida['ok']), json_encode($definida));
    }

    /**
     * Texto pegado, definiciones y tarjeta propuesta, con el assistant ya 'listo'.
     *
     * @param  AiConversation  $conversation
     * @param  string  $canal
     * @return array{0: AiPlanilla, 1: AiMessage}
     */
    protected function tarjeta_lista(AiConversation $conversation, $canal = AiMessage::CANAL_SISTEMA)
    {
        $planilla = $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR, $canal);

        $assistant = $this->assistant($conversation, $canal);

        $this->definir_para_cargar($conversation, $assistant);

        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $assistant->contenido = 'La lista.';
        $assistant->estado = 'listo';
        $assistant->save();

        return [$planilla->fresh(), $assistant];
    }

    /**
     * Las ventas de la planilla, por las observaciones que les deja la tarjeta.
     *
     * @param  AiPlanilla  $planilla
     * @return \Illuminate\Support\Collection
     */
    protected function ventas_de(AiPlanilla $planilla)
    {
        return Sale::where('user_id', $this->dueno->id)
                    ->where('observations', 'LIKE', 'Planilla N° ' . $planilla->id . ',%')
                    ->orderBy('id')
                    ->with('articles')
                    ->get();
    }

    /**
     * Los requests del modelo que conversa (ni el clasificador ni las lecturas).
     *
     * @return array<int, array>
     */
    protected function requests_del_modelo_que_conversa()
    {
        $cuerpos = [];

        foreach (Http::recorded() as $par) {

            $cuerpo = json_decode($par[0]->body(), true);

            if (isset($cuerpo['tools'])) {
                $cuerpos[] = $cuerpo;
            }
        }

        return $cuerpos;
    }

    /** Todo el texto del turno lo responde DeepSeek y ninguna de sus llamadas recibe imágenes. */
    protected function assert_deepseek_conversa_sin_imagenes()
    {
        $conversa = $this->requests_del_modelo_que_conversa();

        $this->assertNotEmpty($conversa);

        foreach ($conversa as $cuerpo) {
            $this->assertStringContainsString('deepseek', (string) $cuerpo['model']);
            $this->assertStringNotContainsString('"type":"image"', json_encode($cuerpo['messages']));
        }

        foreach (Http::recorded() as $par) {
            if (mb_strpos((string) $par[0]->url(), 'api.deepseek.com') !== false) {
                $this->assertStringNotContainsString('"type":"image"', $par[0]->body());
            }
        }
    }

    /**
     * Los requests de lectura de un modelo.
     *
     * @param  string  $modelo
     * @return array<int, array>
     */
    protected function lecturas_de($modelo)
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {

            $cuerpo = json_decode($par[0]->body(), true);

            if (($cuerpo['model'] ?? '') === $modelo && !isset($cuerpo['tools']) && mb_strpos($par[0]->body(), 'Transcrib') !== false) {
                $pedidos[] = $par;
            }
        }

        return $pedidos;
    }
}
