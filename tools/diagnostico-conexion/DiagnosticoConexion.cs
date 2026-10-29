using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Security;
using System.Net.Sockets;
using System.Net.WebSockets;
using System.Security.Authentication;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using System.Web;
using System.Web.Script.Serialization;
using System.Windows.Forms;

namespace ComercioCity.DiagnosticoConexion
{
    public sealed class Medicion
    {
        public string fase { get; set; }
        public string objetivo { get; set; }
        public string estado { get; set; }
        public double ms { get; set; }
        public int codigo { get; set; }
        public string detalle { get; set; }
    }
    public sealed class Informe
    {
        public int esquema = 1;
        public string version = "1.0.0";
        public string ticket = "27";
        public string inicio_utc;
        public string fin_utc;
        public string conclusion;
        public bool cancelado;
        public List<Medicion> mediciones = new List<Medicion>();
        public List<string> hallazgos = new List<string>();
        public List<string> limites = new List<string>();
    }
    public static class Seguridad
    {
        public static readonly string[] Hosts = { "innovate.comerciocity.com", "api-innovate.comerciocity.com", "www.microsoft.com", "www.cloudflare.com" };
        public static bool Permitida(string url)
        {
            Uri u;
            return Uri.TryCreate(url, UriKind.Absolute, out u) && u.Scheme == "https" && u.Port == 443 && String.IsNullOrEmpty(u.UserInfo) && Hosts.Contains(u.DnsSafeHost, StringComparer.OrdinalIgnoreCase);
        }
        public static string Ruta(string url)
        {
            Uri u;
            if (!Permitida(url) || !Uri.TryCreate(url, UriKind.Absolute, out u)) return "destino omitido";
            // Nunca se exportan query, fragmento, credenciales ni identificadores dinámicos.
            string categoria = u.AbsolutePath == "/login" ? "acceso público" : u.AbsolutePath == "/public/sanctum/csrf-cookie" ? "inicio público de API" : u.AbsolutePath == "/" ? "raíz pública" : "recurso público (ruta omitida)";
            return u.DnsSafeHost + " · " + categoria;
        }
        public static string Texto(string s)
        {
            if (s == null) return "";
            s = Regex.Replace(s, @"[\r\n\t]+", " ");
            return s.Length > 160 ? s.Substring(0, 160) : s;
        }
        public static string Error(Exception e)
        {
            var w = e as WebException;
            if (w != null) return "WebException:" + w.Status;
            var s = e as SocketException;
            if (s != null) return "SocketException:" + s.SocketErrorCode;
            if (e is OperationCanceledException || e is TaskCanceledException) return "Cancelado o tiempo agotado";
            // Los mensajes crudos pueden contener rutas personales, IP o un proxy con usuario.
            return e.GetType().Name;
        }
    }
    public sealed class Motor
    {
        public const string Sitio = "https://innovate.comerciocity.com/login";
        public const string Api = "https://api-innovate.comerciocity.com/public/sanctum/csrf-cookie";
        public Informe Resultado = new Informe();
        readonly Action<string> progreso;
        readonly CancellationToken cancelar;
        public Motor(Action<string> p, CancellationToken token) { progreso = p; cancelar = token; }
        void Agregar(string fase, string objetivo, string estado, double ms, int codigo, string detalle)
        {
            Resultado.mediciones.Add(new Medicion { fase = fase, objetivo = objetivo, estado = estado, ms = Math.Round(ms, 1), codigo = codigo, detalle = detalle });
        }
        public async Task<Informe> Ejecutar(bool navegador)
        {
            Resultado.inicio_utc = DateTime.UtcNow.ToString("o");
            Resultado.limites.Add("Sólo se midieron recursos públicos. No se inició sesión ni se probaron ventas, compras o guardados.");
            Resultado.limites.Add("No se leyeron contraseñas, cookies, historial ni datos del negocio. El informe queda local y no se envía automáticamente.");
            Resultado.limites.Add("La presencia de un antivirus, VPN o proxy no demuestra que interfiera. ICMP sin respuesta no equivale a pérdida de HTTP.");
            Resultado.limites.Add("Una referencia que responde bien no descarta un problema de ruta específico hacia ComercioCity. Repetir en otra conexión ayuda a aislarlo.");
            try
            {
                cancelar.ThrowIfCancellationRequested();
                progreso("1 de 5 · Configuración y adaptadores (sólo lectura)");
                // PAC/WMI pueden tardar fuera del control de .NET: aislar lectura y no esperar indefinidamente.
                var inventario = new Motor(p => { }, cancelar);
                var leerInventario = Task.Run(() => inventario.Inventario());
                if (await Task.WhenAny(leerInventario, Task.Delay(6000, cancelar)) == leerInventario)
                { await leerInventario; Resultado.mediciones.AddRange(inventario.Resultado.mediciones); }
                else { cancelar.ThrowIfCancellationRequested(); Agregar("configuracion", "inventario", "no_medido", 6000, 0, "Lectura de metadatos excedió 6 segundos. No se espera al proveedor PAC/WMI; sin cambiar configuración."); }
                cancelar.ThrowIfCancellationRequested();
                progreso("2 de 5 · Resolución DNS");
                foreach (string host in Seguridad.Hosts) { cancelar.ThrowIfCancellationRequested(); await DnsMedir(host); }
                progreso("3 de 5 · HTTPS, certificados y respuestas públicas");
                foreach (string url in new[] { Sitio, Api, "https://www.microsoft.com/", "https://www.cloudflare.com/" })
                {
                    for (int i = 0; i < 3; i++) { cancelar.ThrowIfCancellationRequested(); await HttpMedir(url, i + 1); }
                    cancelar.ThrowIfCancellationRequested();
                    await TlsMedir(url);
                }
                progreso("4 de 5 · Estabilidad ICMP orientativa");
                await PingMedir();
                cancelar.ThrowIfCancellationRequested();
                progreso("5 de 5 · Navegador con perfil temporal vacío");
                if (navegador) await new Navegador().Medir(Resultado, cancelar, progreso);
                else Agregar("navegador", "perfil temporal", "no_medido", 0, 0, "Prueba omitida por opción de ejecución.");
            }
            catch (OperationCanceledException) { Resultado.cancelado = true; }
            catch (Exception e) { Agregar("ejecucion", "diagnóstico parcial", "error", 0, 0, Seguridad.Error(e)); }
            Resultado.fin_utc = DateTime.UtcNow.ToString("o");
            Interpretar(Resultado);
            return Resultado;
        }
        void Inventario()
        {
            try { Agregar("configuracion", "proxy WinHTTP predeterminado", "informativo", 0, 0, ProxyWinHttp.Estado()); }
            catch (Exception e) { Agregar("configuracion", "proxy WinHTTP predeterminado", "no_medido", 0, 0, Seguridad.Error(e)); }
            try
            {
                bool proxy = !WebRequest.DefaultWebProxy.IsBypassed(new Uri(Sitio));
                Agregar("configuracion", "proxy de usuario para Innovate", "informativo", 0, 0, proxy ? "Configurado. No se cambia ni se evita." : "No se seleccionó un proxy para este destino.");
            }
            catch (Exception e) { Agregar("configuracion", "proxy de usuario", "no_medido", 0, 0, Seguridad.Error(e)); }
            try
            {
                foreach (var n in NetworkInterface.GetAllNetworkInterfaces().Where(x => x.OperationalStatus == OperationalStatus.Up && x.NetworkInterfaceType != NetworkInterfaceType.Loopback))
                {
                    var s = n.GetIPv4Statistics();
                    Agregar("adaptador", n.NetworkInterfaceType.ToString(), "informativo", 0, 0,
                        "Velocidad de enlace: " + (n.Speed / 1000000) + " Mbps; errores recibidos acumulados: " + s.IncomingPacketsWithErrors + "; descartes acumulados: " + s.IncomingPacketsDiscarded + ". No equivalen a la velocidad de Internet ni a errores de esta ejecución.");
                }
            }
            catch (Exception e) { Agregar("adaptador", "estado", "no_medido", 0, 0, Seguridad.Error(e)); }
            try
            {
                var opciones = new EnumerationOptions { Timeout = TimeSpan.FromSeconds(4) };
                using (var q = new ManagementObjectSearcher(new ManagementScope(@"\\.\root\SecurityCenter2"), new ObjectQuery("SELECT displayName FROM AntiVirusProduct"), opciones))
                using (var objetos = q.Get())
                    foreach (ManagementObject o in objetos) Agregar("configuracion", "producto de seguridad registrado", "informativo", 0, 0, Seguridad.Texto(Convert.ToString(o["displayName"])) + ". Su presencia no demuestra interferencia.");
            }
            catch (Exception e) { Agregar("configuracion", "productos de seguridad", "no_medido", 0, 0, Seguridad.Error(e)); }
            try
            {
                string f = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), @"drivers\etc\hosts");
                bool entrada = File.ReadLines(f).Any(line => !line.TrimStart().StartsWith("#") && Seguridad.Hosts.Any(h => Regex.IsMatch(line.Split('#')[0], @"(?<![\w.-])" + Regex.Escape(h) + @"(?![\w.-])", RegexOptions.IgnoreCase)));
                Agregar("configuracion", "archivo hosts", "informativo", 0, 0, entrada ? "Hay entradas para algún destino del diagnóstico. No se exportan sus direcciones ni se modifica el archivo." : "No hay entradas para los destinos del diagnóstico.");
            }
            catch (Exception e) { Agregar("configuracion", "archivo hosts", "no_medido", 0, 0, Seguridad.Error(e)); }
        }
        async Task DnsMedir(string host)
        {
            var reloj = Stopwatch.StartNew();
            try
            {
                var tarea = Dns.GetHostAddressesAsync(host);
                if (await Task.WhenAny(tarea, Task.Delay(5000, cancelar)) != tarea) { cancelar.ThrowIfCancellationRequested(); Agregar("dns", host, "error", reloj.Elapsed.TotalMilliseconds, 0, "Tiempo agotado (5 s)."); return; }
                var direcciones = await tarea;
                Agregar("dns", host, direcciones.Length > 0 ? "ok" : "error", reloj.Elapsed.TotalMilliseconds, 0,
                    "IPv4: " + direcciones.Count(x => x.AddressFamily == AddressFamily.InterNetwork) + "; IPv6: " + direcciones.Count(x => x.AddressFamily == AddressFamily.InterNetworkV6) + ". Se omiten las direcciones.");
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception e) { Agregar("dns", host, "error", reloj.Elapsed.TotalMilliseconds, 0, Seguridad.Error(e)); }
        }
        async Task HttpMedir(string url, int muestra)
        {
            if (!Seguridad.Permitida(url)) throw new InvalidOperationException("Destino no permitido");
            var reloj = Stopwatch.StartNew();
            var peticion = (HttpWebRequest)WebRequest.Create(url);
            peticion.Method = "GET";
            peticion.AllowAutoRedirect = false;
            peticion.Timeout = 8000;
            peticion.ReadWriteTimeout = 8000;
            peticion.UseDefaultCredentials = false;
            peticion.Credentials = null;
            peticion.CookieContainer = new CookieContainer();
            peticion.UserAgent = "ComercioCity-Diagnostico/1.0";
            peticion.AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate;
            using (cancelar.Register(peticion.Abort))
            using (var tiempo = new CancellationTokenSource(10000))
            using (tiempo.Token.Register(peticion.Abort))
            {
                try
                {
                    using (var respuesta = (HttpWebResponse)await peticion.GetResponseAsync())
                    {
                        int codigo = (int)respuesta.StatusCode;
                        // Basta cabecera + lectura acotada: no se persiste el HTML ni el contenido.
                        using (var stream = respuesta.GetResponseStream())
                        {
                            var buffer = new byte[4096];
                            if (stream != null) await stream.ReadAsync(buffer, 0, buffer.Length, cancelar);
                        }
                        bool esperado = url == Api ? codigo == 204 : codigo >= 200 && codigo < 400;
                        Agregar("http", Seguridad.Ruta(url), esperado ? "ok" : "error", reloj.Elapsed.TotalMilliseconds, codigo, "Muestra " + muestra + "; sin autenticación; las redirecciones no se siguen.");
                    }
                }
                catch (WebException e)
                {
                    cancelar.ThrowIfCancellationRequested();
                    var respuesta = e.Response as HttpWebResponse;
                    int codigo = respuesta == null ? 0 : (int)respuesta.StatusCode;
                    if (respuesta != null) respuesta.Dispose();
                    Agregar("http", Seguridad.Ruta(url), "error", reloj.Elapsed.TotalMilliseconds, codigo, "Muestra " + muestra + "; " + Seguridad.Error(e));
                }
                catch (OperationCanceledException) { throw; }
                catch (Exception e) { Agregar("http", Seguridad.Ruta(url), "error", reloj.Elapsed.TotalMilliseconds, 0, Seguridad.Error(e)); }
            }
        }
        async Task TlsMedir(string url)
        {
            Uri u = new Uri(url);
            try
            {
                var evaluarProxy = Task.Run(() => WebRequest.DefaultWebProxy.IsBypassed(u));
                if (await Task.WhenAny(evaluarProxy, Task.Delay(2500, cancelar)) != evaluarProxy) { cancelar.ThrowIfCancellationRequested(); Agregar("tls", u.Host, "no_medido", 2500, 0, "Consulta de proxy excedió el límite; no se fuerza conexión directa."); return; }
                if (!await evaluarProxy) { Agregar("tls", u.Host, "no_medido", 0, 0, "Prueba TCP directa omitida porque hay proxy configurado. HTTPS ya se probó respetándolo."); return; }
            }
            catch { Agregar("tls", u.Host, "no_medido", 0, 0, "No se pudo determinar la ruta de proxy; no se fuerza conexión directa."); return; }
            var reloj = Stopwatch.StartNew();
            using (var tcp = new TcpClient())
            using (cancelar.Register(tcp.Close))
            {
                try
                {
                    var conectar = tcp.ConnectAsync(u.Host, 443);
                    if (await Task.WhenAny(conectar, Task.Delay(5000, cancelar)) != conectar) { cancelar.ThrowIfCancellationRequested(); throw new TimeoutException(); }
                    await conectar;
                    Agregar("tcp", u.Host, "ok", reloj.Elapsed.TotalMilliseconds, 0, "Puerto HTTPS 443.");
                    using (var ssl = new SslStream(tcp.GetStream(), false))
                    {
                        var autenticar = ssl.AuthenticateAsClientAsync(u.Host, null, SslProtocols.Tls12, true);
                        if (await Task.WhenAny(autenticar, Task.Delay(5000, cancelar)) != autenticar) { cancelar.ThrowIfCancellationRequested(); throw new TimeoutException(); }
                        await autenticar;
                        Agregar("tls", u.Host, "ok", reloj.Elapsed.TotalMilliseconds, 0, "Certificado y nombre validados por Windows; protocolo " + ssl.SslProtocol + ". No se omite ninguna validación.");
                    }
                }
                catch (OperationCanceledException) { throw; }
                catch (Exception e) { cancelar.ThrowIfCancellationRequested(); Agregar("tls", u.Host, "error", reloj.Elapsed.TotalMilliseconds, 0, Seguridad.Error(e)); }
            }
        }
        async Task PingMedir()
        {
            var objetivos = new List<KeyValuePair<string, IPAddress>>();
            try
            {
                var gateway = NetworkInterface.GetAllNetworkInterfaces().Where(n => n.OperationalStatus == OperationalStatus.Up).SelectMany(n => n.GetIPProperties().GatewayAddresses).Select(g => g.Address).FirstOrDefault(a => a.AddressFamily == AddressFamily.InterNetwork && !a.Equals(IPAddress.Any));
                if (gateway != null) objetivos.Add(new KeyValuePair<string, IPAddress>("puerta de enlace (dirección omitida)", gateway));
            }
            catch { }
            objetivos.Add(new KeyValuePair<string, IPAddress>("referencia ICMP de Cloudflare", IPAddress.Parse("1.1.1.1")));
            foreach (var objetivo in objetivos)
            {
                int exitos = 0; var rtts = new List<long>();
                for (int i = 0; i < 8; i++)
                {
                    cancelar.ThrowIfCancellationRequested();
                    try { using (var p = new Ping()) { var r = await p.SendPingAsync(objetivo.Value, 700); if (r.Status == IPStatus.Success) { exitos++; rtts.Add(r.RoundtripTime); } } }
                    catch { }
                }
                Agregar("icmp", objetivo.Key, exitos == 8 ? "ok" : "orientativo", rtts.Count > 0 ? rtts.Average() : 0, 0,
                    "Respuestas: " + exitos + "/8. Si faltan respuestas puede ser filtrado ICMP; no prueba pérdida de HTTP. Promedio sólo de respuestas recibidas.");
            }
        }
        public static void Interpretar(Informe informe)
        {
            informe.hallazgos.Clear();
            var http = informe.mediciones.Where(m => m.fase == "http").ToList();
            bool servidor = http.Any(m => m.objetivo.Contains("innovate.comerciocity.com") && m.codigo >= 500);
            bool dns = informe.mediciones.Any(m => m.fase == "dns" && m.estado == "error");
            bool tls = informe.mediciones.Any(m => m.fase == "tls" && m.estado == "error");
            bool navegador = informe.mediciones.Any(m => m.fase == "navegador" && m.estado == "error");
            if (servidor) informe.hallazgos.Add("Se recibió HTTP 5xx para algún recurso de Innovate. Investigar origen, ruta e intermediarios; el código no prueba por sí solo dónde está la causa.");
            if (http.Any(m => m.estado == "error" && m.codigo > 0 && m.codigo < 500)) informe.hallazgos.Add("Se recibió un código HTTP inesperado en un recurso público. Revisar destino y código con soporte; puede depender del acceso o de un intermediario.");
            if (dns) informe.hallazgos.Add("Hubo fallas de resolución DNS durante esta ejecución. Comparar otra conexión y otra computadora antes de atribuirlas al equipo o al proveedor.");
            if (tls) informe.hallazgos.Add("Hubo fallas en TCP/TLS. Puede influir la ruta, certificados, reloj, proxy o servidor. No se desactivó ninguna protección.");
            if (http.Any(m => m.estado == "error" && m.codigo == 0)) informe.hallazgos.Add("Alguna solicitud HTTPS no obtuvo una respuesta HTTP. Revisar su código de error y comparar con las referencias.");
            if (navegador) informe.hallazgos.Add("El navegador con perfil temporal registró una falla. El detalle de red permite orientar el diagnóstico, pero no identifica por sí solo un programa culpable.");
            if (http.Any(m => m.ms >= 3000)) informe.hallazgos.Add("Alguna solicitud pública tardó al menos 3 segundos en esta ejecución.");
            if (!informe.hallazgos.Any()) informe.hallazgos.Add("No se detectó una falla concluyente en las pruebas completadas. Eso no descarta intermitencias ni problemas del perfil habitual o de operaciones autenticadas.");
            informe.conclusion = informe.cancelado ? "Diagnóstico parcial: cancelado por el usuario." : "Evidencia para revisar con soporte. La causa no se determina automáticamente.";
            informe.limites.Add("Este informe no certifica que la PC esté averiada ni recomienda repararla por sí solo. Un técnico debe contrastar los hallazgos antes de cambiar componentes.");
        }
    }
    public static class ProxyWinHttp
    {
        [StructLayout(LayoutKind.Sequential)] struct Configuracion { public int acceso; public IntPtr proxy; public IntPtr omisiones; }
        [DllImport("winhttp.dll", SetLastError = true)] static extern bool WinHttpGetDefaultProxyConfiguration(out Configuracion configuracion);
        [DllImport("kernel32.dll")] static extern IntPtr GlobalFree(IntPtr memoria);
        public static string Estado()
        {
            Configuracion c;
            if (!WinHttpGetDefaultProxyConfiguration(out c)) return "No se pudo leer. No se cambió la configuración.";
            try { return c.acceso == 3 ? "Hay proxy explícito predeterminado. Su presencia no demuestra interferencia. No se exporta su dirección." : "Sin proxy explícito predeterminado. No describe necesariamente el proxy de cada aplicación."; }
            finally { if (c.proxy != IntPtr.Zero) GlobalFree(c.proxy); if (c.omisiones != IntPtr.Zero) GlobalFree(c.omisiones); }
        }
    }
    public sealed class Navegador
    {
        readonly JavaScriptSerializer json = new JavaScriptSerializer { MaxJsonLength = 2000000 };
        static string Ejecutable()
        {
            foreach (string raiz in new[] { Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData) })
                foreach (string rel in new[] { @"Microsoft\Edge\Application\msedge.exe", @"Google\Chrome\Application\chrome.exe" })
                { string f = Path.Combine(raiz, rel); if (File.Exists(f)) return f; }
            return null;
        }
        public async Task Medir(Informe informe, CancellationToken cancelar, Action<string> progreso)
        {
            string exe = Ejecutable();
            if (exe == null) { informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "perfil temporal", estado = "no_medido", detalle = "No se encontró Edge o Chrome en las rutas habituales. No se instaló nada." }); return; }
            string baseTemporal = Path.Combine(Path.GetTempPath(), "ComercioCityDiagnostico");
            string identificador = Guid.NewGuid().ToString("N");
            string perfil = Path.Combine(baseTemporal, identificador);
            Directory.CreateDirectory(baseTemporal);
            if ((File.GetAttributes(baseTemporal) & FileAttributes.ReparsePoint) != 0) { informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "perfil temporal", estado = "no_medido", detalle = "Carpeta temporal con redirección; no se usa." }); return; }
            Directory.CreateDirectory(perfil);
            File.WriteAllText(Path.Combine(perfil, ".cc-propio"), identificador);
            Process proceso = null;
            var peticiones = new Dictionary<string, string>();
            var respuestas = new HashSet<string>();
            var fallos = new HashSet<string>();
            var finalizadas = new HashSet<string>();
            int puertoPropio = 0;
            ClientWebSocket socketPropio = null;
            Uri cierrePropio = null;
            string estadoCierre = "sin endpoint propio";
            try
            {
                // Perfil propio y vacío: nunca se abre ni se copia el perfil real del cliente.
                proceso = Process.Start(new ProcessStartInfo(exe, "--user-data-dir=\"" + perfil + "\" --remote-debugging-address=127.0.0.1 --remote-debugging-port=0 --no-first-run --no-default-browser-check --disable-sync about:blank") { UseShellExecute = false, CreateNoWindow = true });
                string archivoPuerto = Path.Combine(perfil, "DevToolsActivePort");
                var espera = Stopwatch.StartNew();
                while (!File.Exists(archivoPuerto) && espera.ElapsedMilliseconds < 12000) { cancelar.ThrowIfCancellationRequested(); await Task.Delay(150, cancelar); }
                if (!File.Exists(archivoPuerto)) throw new TimeoutException();
                int puerto;
                var lineas = File.ReadAllLines(archivoPuerto);
                if (lineas.Length < 1 || !Int32.TryParse(lineas[0], out puerto) || puerto < 1024 || puerto > 65535) throw new InvalidDataException();
                puertoPropio = puerto;
                if (lineas.Length >= 2 && Regex.IsMatch(lineas[1], @"^/devtools/browser/[a-zA-Z0-9-]+$")) cierrePropio = new Uri("ws://127.0.0.1:" + puerto + lineas[1]);
                string listado;
                listado = await Descubrir(puerto, cancelar);
                var paginas = json.DeserializeObject(listado) as object[];
                if (paginas == null) throw new InvalidDataException();
                var pagina = paginas.OfType<Dictionary<string, object>>().FirstOrDefault(p => Convert.ToString(p["type"]) == "page" && Convert.ToString(p["url"]) == "about:blank");
                if (pagina == null) throw new InvalidDataException();
                Uri ws = new Uri(Convert.ToString(pagina["webSocketDebuggerUrl"]));
                if (ws.Scheme != "ws" || ws.Host != "127.0.0.1" || ws.Port != puerto || !ws.AbsolutePath.StartsWith("/devtools/page/")) throw new InvalidDataException();
                var socket = socketPropio = new ClientWebSocket();
                using (var limite = CancellationTokenSource.CreateLinkedTokenSource(cancelar))
                {
                    limite.CancelAfter(18000);
                    socket.Options.Proxy = null;
                    await socket.ConnectAsync(ws, limite.Token);
                    await Enviar(socket, 1, "Network.enable", new Dictionary<string, object>(), limite.Token);
                    await Enviar(socket, 2, "Page.enable", new Dictionary<string, object>(), limite.Token);
                    await Enviar(socket, 3, "Page.navigate", new Dictionary<string, object> { { "url", Motor.Sitio } }, limite.Token);
                    var tiempo = Stopwatch.StartNew();
                    while (tiempo.ElapsedMilliseconds < 16000 && socket.State == WebSocketState.Open)
                    {
                        var evento = await Recibir(socket, limite.Token);
                        object metodoObj;
                        if (!evento.TryGetValue("method", out metodoObj)) continue;
                        string metodo = Convert.ToString(metodoObj);
                        var parametros = evento.ContainsKey("params") ? evento["params"] as Dictionary<string, object> : null;
                        if (parametros == null) continue;
                        string id = parametros.ContainsKey("requestId") ? Convert.ToString(parametros["requestId"]) : "";
                        if (metodo == "Network.requestWillBeSent")
                        {
                            var req = parametros["request"] as Dictionary<string, object>;
                            string url = req == null ? "" : Convert.ToString(req["url"]);
                            if (Seguridad.Permitida(url)) peticiones[id] = Seguridad.Ruta(url);
                            else { peticiones.Remove(id); respuestas.Remove(id); finalizadas.Remove(id); fallos.Remove(id); }
                        }
                        if (!peticiones.ContainsKey(id)) continue;
                        if (metodo == "Network.loadingFinished") finalizadas.Add(id);
                        if (metodo == "Network.responseReceived" && respuestas.Add(id))
                        {
                            var r = parametros["response"] as Dictionary<string, object>;
                            int codigo = Convert.ToInt32(r["status"]);
                            informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = peticiones[id], estado = codigo >= 500 ? "error" : "informativo", codigo = codigo, detalle = "Cabeceras recibidas en perfil temporal; no prueba descarga completa. Un 401/403 puede ser esperado en un recurso autenticado." });
                        }
                        if (metodo == "Network.loadingFailed" && fallos.Add(id))
                        {
                            string error = Convert.ToString(parametros["errorText"]);
                            // Sólo códigos de Chromium conocidos por su forma; nunca mensajes arbitrarios.
                            string seguro = Regex.Match(error ?? "", @"(?:net::)?ERR_[A-Z0-9_]+\b").Value;
                            informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = peticiones[id], estado = "error", detalle = seguro.Length > 0 ? seguro : "Falla de carga sin código exportable." });
                        }
                    }
                    using (var cierre = new CancellationTokenSource(1500))
                        if (socket.State == WebSocketState.Open) await Enviar(socket, 4, "Browser.close", new Dictionary<string, object>(), cierre.Token);
                }
                if (peticiones.Count == 0) throw new InvalidDataException();
            }
            catch (OperationCanceledException) { if (cancelar.IsCancellationRequested) throw; informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "perfil temporal", estado = "parcial", detalle = "Terminó la ventana de observación. Se conservan los eventos obtenidos." }); }
            catch (Exception e) { informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "perfil temporal", estado = "no_medido", detalle = Seguridad.Error(e) }); }
            finally
            {
                if (socketPropio != null)
                {
                    try
                    {
                        // Token propio breve: incluso una cancelación del usuario debe cerrar sólo este navegador.
                        using (var cierre = new CancellationTokenSource(1500))
                            if (socketPropio.State == WebSocketState.Open) Enviar(socketPropio, 99, "Browser.close", new Dictionary<string, object>(), cierre.Token).GetAwaiter().GetResult();
                    }
                    catch { }
                    socketPropio.Dispose();
                }
                if (cierrePropio != null)
                {
                    try
                    {
                        // ReceiveAsync cancelado puede abortar el socket de página: el endpoint propio de navegador permite cerrarlo igualmente.
                        using (var cierre = new CancellationTokenSource(2000))
                        using (var control = new ClientWebSocket())
                        {
                            control.Options.Proxy = null;
                            estadoCierre = "conectando control propio";
                            control.ConnectAsync(cierrePropio, cierre.Token).GetAwaiter().GetResult();
                            estadoCierre = "enviando cierre propio";
                            Enviar(control, 100, "Browser.close", new Dictionary<string, object>(), cierre.Token).GetAwaiter().GetResult();
                            estadoCierre = "esperando confirmación propia";
                            // Esperar confirmación o cierre remoto antes de disponer el transporte.
                            Recibir(control, cierre.Token).GetAwaiter().GetResult();
                        }
                    }
                    catch (Exception e) { estadoCierre += ": " + Seguridad.Error(e); }
                }
                bool cerrado = proceso == null;
                if (proceso != null)
                {
                    try { if (!proceso.HasExited) { proceso.CloseMainWindow(); if (!proceso.WaitForExit(2000)) { proceso.Kill(); proceso.WaitForExit(2000); } } cerrado = proceso.HasExited; } catch { }
                    proceso.Dispose();
                }
                bool salidaConfirmada = cerrado;
                if (puertoPropio != 0)
                {
                    bool puertoCerrado = false; var esperaCierre = Stopwatch.StartNew();
                    while (!puertoCerrado && esperaCierre.ElapsedMilliseconds < 5000)
                    {
                        try { puertoCerrado = !IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners().Any(ep => ep.Port == puertoPropio); }
                        catch { }
                        if (!puertoCerrado) Thread.Sleep(150);
                    }
                    cerrado = cerrado && puertoCerrado;
                }
                bool limpio = false;
                try
                {
                    string ruta = Path.GetFullPath(perfil);
                    string raiz = Path.GetFullPath(baseTemporal).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
                    if (cerrado && ruta.StartsWith(raiz, StringComparison.OrdinalIgnoreCase) && Path.GetFileName(ruta) == identificador && ArbolSeguro(ruta) && File.ReadAllText(Path.Combine(ruta, ".cc-propio")) == identificador) { Directory.Delete(ruta, true); limpio = !Directory.Exists(ruta); }
                }
                catch { /* Se conserva el perfil propio si Windows aún lo bloquea; nunca se borra otro. */ }
                informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "resumen del perfil temporal", estado = peticiones.Count == 0 ? "no_medido" : "informativo", detalle = Resumen(peticiones.Count, respuestas.Count, finalizadas.Count, fallos.Count) });
                informe.mediciones.Add(new Medicion { fase = "navegador", objetivo = "cierre y limpieza propios", estado = cerrado && limpio ? "informativo" : "parcial", detalle = "Salida del proceso propio " + (salidaConfirmada ? "confirmada" : "no confirmada") + "; puerto CDP " + (cerrado ? "cerrado" : "sin confirmar") + "; perfil " + (limpio ? "eliminado" : "conservado") + ". Control: " + estadoCierre + "." });
                informe.limites.Add("El perfil temporal no representa extensiones, cookies ni preferencias del perfil habitual; no prueba hardware. Solicitudes pendientes pueden ser conexiones persistentes o cargas aún incompletas.");
            }
            informe.limites.Add("El navegador se probó con un perfil temporal vacío. No representa las extensiones, cookies o preferencias del perfil habitual. Una prueba sana no descarta fallas en ese perfil ni demuestra un problema de hardware.");
        }
        public static string Resumen(int observadas, int cabeceras, int completas, int fallidas)
        {
            return "Solicitudes observadas: " + observadas + "; con cabeceras: " + cabeceras + "; finalizadas: " + completas + "; fallidas: " + fallidas + "; pendientes al cierre: " + Math.Max(0, observadas - completas - fallidas) + ". Sin login ni extensiones del perfil habitual.";
        }
        public static bool ArbolSeguro(string ruta)
        {
            var actual = new DirectoryInfo(Path.GetFullPath(ruta));
            for (var padre = actual; padre != null; padre = padre.Parent)
                if ((padre.Attributes & FileAttributes.ReparsePoint) != 0) return false;
            foreach (var entrada in actual.EnumerateFileSystemInfos())
            {
                if ((entrada.Attributes & FileAttributes.ReparsePoint) != 0) return false;
                if ((entrada.Attributes & FileAttributes.Directory) != 0 && !ArbolSeguro(entrada.FullName)) return false;
            }
            return true;
        }
        public static async Task<string> Descubrir(int puerto, CancellationToken cancelar)
        {
            var solicitud = (HttpWebRequest)WebRequest.Create("http://127.0.0.1:" + puerto + "/json/list");
            solicitud.Proxy = null; solicitud.AllowAutoRedirect = false; solicitud.UseDefaultCredentials = false;
            solicitud.Timeout = 2500; solicitud.ReadWriteTimeout = 2500;
            using (var limite = CancellationTokenSource.CreateLinkedTokenSource(cancelar))
            {
                limite.CancelAfter(2500);
                using (limite.Token.Register(solicitud.Abort))
                using (var respuesta = await solicitud.GetResponseAsync())
                using (var flujo = respuesta.GetResponseStream())
                using (var memoria = new MemoryStream())
                {
                    var buffer = new byte[4096]; int leidos;
                    while ((leidos = await flujo.ReadAsync(buffer, 0, buffer.Length, limite.Token)) > 0)
                    { memoria.Write(buffer, 0, leidos); if (memoria.Length > 2000000) throw new InvalidDataException(); }
                    return Encoding.UTF8.GetString(memoria.ToArray());
                }
            }
        }
        async Task Enviar(ClientWebSocket socket, int id, string metodo, object parametros, CancellationToken token)
        {
            var datos = Encoding.UTF8.GetBytes(json.Serialize(new Dictionary<string, object> { { "id", id }, { "method", metodo }, { "params", parametros } }));
            await socket.SendAsync(new ArraySegment<byte>(datos), WebSocketMessageType.Text, true, token);
        }
        async Task<Dictionary<string, object>> Recibir(ClientWebSocket socket, CancellationToken token)
        {
            var buffer = new byte[8192];
            using (var memoria = new MemoryStream())
            {
                WebSocketReceiveResult r;
                do
                {
                    r = await socket.ReceiveAsync(new ArraySegment<byte>(buffer), token);
                    if (r.MessageType == WebSocketMessageType.Close) throw new IOException();
                    memoria.Write(buffer, 0, r.Count);
                    if (memoria.Length > 2000000) throw new InvalidDataException();
                } while (!r.EndOfMessage);
                return json.DeserializeObject(Encoding.UTF8.GetString(memoria.ToArray())) as Dictionary<string, object>;
            }
        }
    }
    public static class Exportar
    {
        public static string Guardar(Informe informe, string carpeta)
        {
            Directory.CreateDirectory(carpeta);
            string json = new JavaScriptSerializer { MaxJsonLength = 5000000 }.Serialize(informe);
            File.WriteAllText(Path.Combine(carpeta, "diagnostico.json"), json, new UTF8Encoding(false));
            string html = Html(informe);
            string archivo = Path.Combine(carpeta, "diagnostico.html");
            File.WriteAllText(archivo, html, new UTF8Encoding(false));
            return archivo;
        }
        public static string Html(Informe r)
        {
            Func<string, string> h = HttpUtility.HtmlEncode;
            var b = new StringBuilder("<!doctype html><html lang='es'><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Diagnóstico de conexión · ComercioCity</title><style>body{margin:0;background:#f5f6f8;color:#18202d;font:16px system-ui,Segoe UI,sans-serif}main{max-width:1100px;padding:30px;margin:auto}section{background:white;padding:22px;margin:18px 0;border:1px solid #dde2e8;border-radius:14px}h1{font-size:28px}h2{font-size:20px}small{color:#586575}.tabla{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:680px}td,th{text-align:left;padding:12px;border-bottom:1px solid #e7ebef;vertical-align:top}td{overflow-wrap:anywhere}.ok{color:#146a45}.error{color:#a52626}li{margin:10px 0}@media(max-width:600px){main{padding:15px}section{padding:15px}h1{font-size:24px}}</style><main><h1>Diagnóstico de conexión</h1><small>ComercioCity · ticket 27 · esquema 1 · ");
            b.Append(h(r.inicio_utc)).Append("</small><section><h2>Resultado</h2><p>").Append(h(r.conclusion)).Append("</p><ul>");
            foreach (string hallazgo in r.hallazgos) b.Append("<li>").Append(h(hallazgo)).Append("</li>");
            b.Append("</ul></section><section><h2>Mediciones</h2><div class='tabla'><table><thead><tr><th>Prueba</th><th>Destino</th><th>Estado</th><th>Tiempo</th><th>HTTP</th><th>Detalle</th></tr></thead><tbody>");
            foreach (var m in r.mediciones) b.Append("<tr><td>").Append(h(m.fase)).Append("</td><td>").Append(h(m.objetivo)).Append("</td><td class='").Append(m.estado == "ok" ? "ok" : m.estado == "error" ? "error" : "").Append("'>").Append(h(m.estado)).Append("</td><td>").Append(m.ms.ToString("0.0", System.Globalization.CultureInfo.InvariantCulture)).Append(" ms</td><td>").Append(m.codigo == 0 ? "—" : m.codigo.ToString()).Append("</td><td>").Append(h(m.detalle)).Append("</td></tr>");
            b.Append("</tbody></table></div></section><section><h2>Alcance y límites</h2><ul>");
            foreach (string limite in r.limites.Distinct()) b.Append("<li>").Append(h(limite)).Append("</li>");
            return b.Append("</ul><p>Compartí diagnostico.html y diagnostico.json con soporte sólo después de revisarlos. Este programa no los sube.</p></section></main></html>").ToString();
        }
    }
    public sealed class Ventana : Form
    {
        readonly Button iniciar = new Button { Text = "Iniciar diagnóstico", AutoSize = true };
        readonly Button detener = new Button { Text = "Cancelar", AutoSize = true, Enabled = false };
        readonly Button abrir = new Button { Text = "Abrir carpeta del informe", AutoSize = true, Enabled = false };
        readonly Label estado = new Label { Text = "Listo para comenzar.", AutoSize = true };
        readonly TextBox salida = new TextBox { ReadOnly = true, Multiline = true, ScrollBars = ScrollBars.Vertical, Dock = DockStyle.Fill };
        CancellationTokenSource cancelar;
        string carpeta;
        public Ventana()
        {
            Text = "ComercioCity · Diagnóstico de conexión"; Width = 820; Height = 600; MinimumSize = new Size(380, 420); Font = new Font("Segoe UI", 10);
            var estructura = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(22), ColumnCount = 1, RowCount = 4 };
            estructura.RowStyles.Add(new RowStyle(SizeType.AutoSize)); estructura.RowStyles.Add(new RowStyle(SizeType.AutoSize)); estructura.RowStyles.Add(new RowStyle(SizeType.AutoSize)); estructura.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
            var aviso = new Label { AutoSize = true, MaximumSize = new Size(730, 0), Text = "Pruebas de sólo lectura para el ticket 27 de Innovate.\r\nNo requiere instalación ni administrador. No cambia la red ni desactiva protecciones. No lee contraseñas ni datos del negocio. Puede abrir un navegador con un perfil temporal vacío durante unos segundos. El informe queda en esta PC y no se envía solo.\r\nDuración aproximada: 2 a 4 minutos. Podés cancelar y guardar lo obtenido." };
            var botones = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Fill };
            botones.Controls.Add(iniciar); botones.Controls.Add(detener); botones.Controls.Add(abrir);
            estructura.Controls.Add(aviso); estructura.Controls.Add(botones); estructura.Controls.Add(estado); estructura.Controls.Add(salida); Controls.Add(estructura);
            iniciar.Click += async delegate
            {
                iniciar.Enabled = false; detener.Enabled = true; abrir.Enabled = false; salida.Clear(); cancelar = new CancellationTokenSource();
                carpeta = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "ComercioCity", "Diagnostico-27-" + DateTime.Now.ToString("yyyyMMdd-HHmmss"));
                try
                {
                    var motor = new Motor(p => BeginInvoke((Action)(() => { estado.Text = p; salida.AppendText(p + Environment.NewLine); })), cancelar.Token);
                    var informe = await Task.Run(() => motor.Ejecutar(true));
                    Exportar.Guardar(informe, carpeta); salida.AppendText(Environment.NewLine + informe.conclusion + Environment.NewLine + String.Join(Environment.NewLine, informe.hallazgos));
                    estado.Text = "Informe guardado. Revisalo antes de compartirlo."; abrir.Enabled = true;
                }
                catch (Exception e) { estado.Text = "No se pudo guardar el informe: " + Seguridad.Error(e); }
                finally { iniciar.Enabled = true; detener.Enabled = false; cancelar.Dispose(); cancelar = null; }
            };
            detener.Click += delegate { if (cancelar != null) cancelar.Cancel(); };
            abrir.Click += delegate { if (carpeta != null) Process.Start("explorer.exe", "\"" + carpeta + "\""); };
            FormClosing += delegate(object s, FormClosingEventArgs e) { if (cancelar != null) { cancelar.Cancel(); e.Cancel = true; estado.Text = "Cancelando y guardando resultados parciales. Cerrá de nuevo al terminar."; } };
        }
    }
    public static class Programa
    {
        [STAThread]
        public static int Main(string[] args)
        {
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
            if (args.Contains("--autoprueba")) return Autopruebas.Ejecutar();
            if (args.Contains("--diagnostico"))
            {
                try
                {
                    string carpeta = Path.Combine(Environment.CurrentDirectory, "resultado-diagnostico");
                    int indice = Array.IndexOf(args, "--salida"); if (indice >= 0 && indice + 1 < args.Length) carpeta = args[indice + 1];
                    var r = new Motor(Console.WriteLine, CancellationToken.None).Ejecutar(!args.Contains("--sin-navegador")).GetAwaiter().GetResult();
                    Console.WriteLine(Exportar.Guardar(r, carpeta)); return r.cancelado ? 2 : 0;
                }
                catch (Exception e) { Console.Error.WriteLine(Seguridad.Error(e)); return 1; }
            }
            Application.EnableVisualStyles(); Application.SetCompatibleTextRenderingDefault(false); Application.Run(new Ventana()); return 0;
        }
    }
    public static class Autopruebas
    {
        public static int Ejecutar()
        {
            int casos = 0;
            Action<bool, string> verificar = (ok, nombre) => { if (!ok) throw new Exception("Falló: " + nombre); casos++; Console.WriteLine("OK: " + nombre); };
            try
            {
                verificar(Seguridad.Permitida(Motor.Sitio), "destino permitido");
                verificar(!Seguridad.Permitida("https://innovate.comerciocity.com.ejemplo.com/"), "no aceptar sufijos engañosos");
                verificar(!Seguridad.Permitida("http://innovate.comerciocity.com/"), "no aceptar HTTP");
                verificar(!Seguridad.Permitida("https://usuario:clave@innovate.comerciocity.com/"), "no aceptar credenciales en URL");
                verificar(!Seguridad.Permitida("https://innovate.comerciocity.com:444/"), "no aceptar otro puerto");
                verificar(!Seguridad.Ruta(Motor.Sitio + "?token=secreto#clave").Contains("secreto"), "redactar query y fragmento");
                verificar(!Seguridad.Ruta("https://innovate.comerciocity.com/api/article/123").Contains("123"), "redactar identificadores");
                verificar(!Seguridad.Ruta("https://innovate.comerciocity.com/reset/SECRETtokenXYZ").Contains("SECRET"), "omitir tokens en segmentos");
                verificar(!Seguridad.Ruta("https://innovate.comerciocity.com/usuarios/mariel").Contains("mariel"), "omitir nombres en segmentos");
                verificar(Navegador.Resumen(2, 2, 0, 0).Contains("pendientes al cierre: 2"), "cabeceras no prueban descarga completa");
                var cancelacion = new CancellationTokenSource(); cancelacion.Cancel();
                var parcial = new Motor(p => { }, cancelacion.Token).Ejecutar(false).GetAwaiter().GetResult();
                verificar(parcial.cancelado && parcial.mediciones.Count == 0, "cancelación previa sin tráfico ni inventario");
                var listener = new TcpListener(IPAddress.Loopback, 0); listener.Start();
                try
                {
                    int puerto = ((IPEndPoint)listener.LocalEndpoint).Port;
                    var reloj = Stopwatch.StartNew(); bool fallo = false;
                    try { Navegador.Descubrir(puerto, CancellationToken.None).GetAwaiter().GetResult(); } catch (WebException e) { fallo = e.Status == WebExceptionStatus.RequestCanceled || e.Status == WebExceptionStatus.Timeout; } catch (OperationCanceledException) { fallo = true; }
                    verificar(fallo && reloj.ElapsedMilliseconds >= 2000 && reloj.ElapsedMilliseconds < 5500, "descubrimiento local sin respuesta termina por timeout real (requiere loopback disponible)");
                }
                finally { listener.Stop(); }
                var inesperado = new Informe(); inesperado.mediciones.Add(new Medicion { fase = "http", objetivo = "público", estado = "error", codigo = 403 }); Motor.Interpretar(inesperado);
                verificar(inesperado.hallazgos.Any(x => x.Contains("inesperado")), "HTTP inesperado no queda oculto");
                var limpio = new Informe(); Motor.Interpretar(limpio);
                verificar(limpio.hallazgos.Any(x => x.Contains("No se detectó")), "sin mediciones no afirmar salud total");
                var servidor = new Informe(); servidor.mediciones.Add(new Medicion { fase = "http", objetivo = "api-innovate.comerciocity.com/public", estado = "error", codigo = 503 }); Motor.Interpretar(servidor);
                verificar(servidor.hallazgos.Any(x => x.Contains("5xx")), "error de servidor no culpar PC");
                var ping = new Informe(); ping.mediciones.Add(new Medicion { fase = "icmp", objetivo = "gateway", estado = "orientativo", detalle = "0/8" }); Motor.Interpretar(ping);
                verificar(!ping.hallazgos.Any(x => x.Contains("pérdida de HTTP")), "ICMP bloqueado no inventar pérdida HTTP");
                var av = new Informe(); av.mediciones.Add(new Medicion { fase = "configuracion", objetivo = "antivirus", estado = "informativo", detalle = "Antivirus" }); Motor.Interpretar(av);
                verificar(!av.hallazgos.Any(x => x.Contains("culpable")), "antivirus presente no prueba interferencia");
                var tls = new Informe(); tls.mediciones.Add(new Medicion { fase = "tls", objetivo = "innovate.comerciocity.com", estado = "error" }); Motor.Interpretar(tls);
                verificar(tls.hallazgos.Any(x => x.Contains("TCP/TLS")), "falla TLS orienta sin desactivar protecciones");
                var dns = new Informe(); dns.mediciones.Add(new Medicion { fase = "dns", objetivo = "innovate.comerciocity.com", estado = "error" }); Motor.Interpretar(dns);
                verificar(dns.hallazgos.Any(x => x.Contains("DNS")), "falla DNS identificada");
                var navegador = new Informe(); navegador.mediciones.Add(new Medicion { fase = "navegador", objetivo = "perfil", estado = "no_medido" }); Motor.Interpretar(navegador);
                verificar(!navegador.hallazgos.Any(x => x.Contains("registró una falla")), "navegador no disponible no inventar falla");
                var html = new Informe { conclusion = "<script>alert(1)</script>" }; html.mediciones.Add(new Medicion { fase = "x", detalle = "<img onerror=alert(1)>" });
                verificar(!Exportar.Html(html).Contains("<script>"), "escapar contenido HTML");
                verificar(Seguridad.Error(new Exception(@"C:\Usuarios\persona\clave token=secreto")) == "Exception", "no exportar mensajes con datos privados");
                Console.WriteLine("Autopruebas aprobadas: " + casos); return 0;
            }
            catch (Exception e) { Console.Error.WriteLine(e.Message); return 1; }
        }
    }
}
