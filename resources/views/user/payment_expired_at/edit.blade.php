<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurador de fecha de pago</title>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px 0;
        }

        .container {
            width: 100%;
            margin: 30px 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-box {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
        }

        h1 {
            text-align: center;
            margin-bottom: 25px;
            color: #333;
        }

        label {
            display: block;
            margin-top: 15px;
            color: #333;
            font-weight: bold;
        }

        input {
            width: 100%;
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-secondary {
            width: 100%;
            background-color: #6c757d;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        button[type="submit"] {
            margin-top: 20px;
            width: 100%;
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        button[type="submit"]:hover {
            background-color: #0056b3;
        }

        .status {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }

        .error {
            color: #b00020;
            text-align: center;
            margin-bottom: 15px;
        }

        .hint {
            color: #666;
            font-size: 13px;
            margin-top: 8px;
        }

        /* Tabla de desglose del total mensualidad */
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 14px;
        }

        .breakdown-table th,
        .breakdown-table td {
            padding: 8px 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .breakdown-table th {
            background-color: #f5f5f5;
            color: #555;
            font-weight: bold;
        }

        .breakdown-table tr.total-row td {
            font-weight: bold;
            background-color: #eaf0fb;
            border-top: 2px solid #007bff;
        }

        /* Fila resaltada para ítems activos */
        .breakdown-table tr.active-row td {
            color: #333;
        }

        /* Fila atenuada para ítems no activos */
        .breakdown-table tr.inactive-row td {
            color: #bbb;
        }

        /* Indicador de precio personalizado en la tabla */
        .precio-custom {
            display: inline-block;
            margin-left: 6px;
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 11px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-box">
        <h1>Fecha de pago de {{ $user->company_name }}</h1>

        @if(session('success'))
            <p class="status">{{ session('success') }}</p>
        @endif

        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('users.payment_expired_at.update', $user->id) }}">
            @csrf
            @method('PUT')

            <label for="payment_expired_at">Fecha de próximo pago</label>
            <input
                type="date"
                id="payment_expired_at"
                name="payment_expired_at"
                value="{{ $user->payment_expired_at ? $user->payment_expired_at->format('Y-m-d') : '' }}"
                required
            >

            <div class="hint">
                Usá los botones para ajustar 1 mes y luego guardá.
            </div>

            <div class="btn-row">
                <button type="button" class="btn-secondary" id="btn_sub_month">Atrasar 1 mes</button>
                <button type="button" class="btn-secondary" id="btn_add_month">Adelantar 1 mes</button>
            </div>
            <hr>


            <h2>
                Total a pagar
            </h2>

            <label for="precio_por_cuenta">Total a pagar por cuenta</label>
            <input
                type="number"
                id="precio_por_cuenta"
                name="precio_por_cuenta"
                value="{{ old('precio_por_cuenta', $user->precio_por_cuenta) }}"
                step="0.01"
                min="0"
                required
            >

            <div class="hint">
                Este valor se guarda en el usuario (propiedad <strong>precio_por_cuenta</strong>).
                Se usa como precio base para todos los conceptos que no tengan un precio individual definido.
            </div>

            @php
                /*
                 * Cálculo del total de cuentas y mensualidad.
                 *
                 * Se suman los siguientes conceptos:
                 *   - Cuentas base: el dueño + todos los empleados (precio_por_cuenta c/u)
                 *   - Ecommerce: +1 si el usuario tiene la propiedad 'online' seteada
                 *   - Mercado Libre: +1 si tiene la extensión 'mercado_libre'
                 *   - Tienda Nube: +1 si tiene la extensión 'usa_tienda_nube'
                 *
                 * Para ecommerce, ML y TN se usa su precio individual si está definido,
                 * o precio_por_cuenta como fallback.
                 */

                // Cuentas base: dueño (1) + cantidad de empleados
                $cuentas_base = count($user->employees) + 1;

                // Ecommerce: la propiedad 'online' contiene una URL si está activo, o null si no
                $tiene_ecommerce = !empty($user->online);
                $cuentas_ecommerce = $tiene_ecommerce ? 1 : 0;

                // Slugs de las extensiones que tiene asignadas el usuario
                $slugs_extenciones = $user->extencions->pluck('slug')->toArray();

                // Mercado Libre: extensión con slug 'mercado_libre'
                $tiene_mercado_libre = in_array('mercado_libre', $slugs_extenciones);
                $cuentas_mercado_libre = $tiene_mercado_libre ? 1 : 0;

                // Tienda Nube: extensión con slug 'usa_tienda_nube'
                $tiene_tienda_nube = in_array('usa_tienda_nube', $slugs_extenciones);
                $cuentas_tienda_nube = $tiene_tienda_nube ? 1 : 0;

                // Precio efectivo de cada servicio: el individual si está seteado, sino precio_por_cuenta
                $precio_ecommerce_efectivo      = $user->precio_ecommerce      ?? $user->precio_por_cuenta;
                $precio_mercado_libre_efectivo  = $user->precio_mercado_libre  ?? $user->precio_por_cuenta;
                $precio_tienda_nube_efectivo    = $user->precio_tienda_nube    ?? $user->precio_por_cuenta;

                // Total mensualidad sumando cada concepto con su precio correspondiente
                $total_mensualidad = ($user->precio_por_cuenta           * $cuentas_base)
                                   + ($precio_ecommerce_efectivo          * $cuentas_ecommerce)
                                   + ($precio_mercado_libre_efectivo      * $cuentas_mercado_libre)
                                   + ($precio_tienda_nube_efectivo        * $cuentas_tienda_nube);
            @endphp

            {{-- Inputs de precio individual para cada servicio --}}
            <label for="precio_ecommerce">Precio cuenta Ecommerce</label>
            <input
                type="number"
                id="precio_ecommerce"
                name="precio_ecommerce"
                value="{{ old('precio_ecommerce', $user->precio_ecommerce) }}"
                placeholder="Por defecto: precio por cuenta (${{ number_format($user->precio_por_cuenta, 0, '', '.') }})"
                step="0.01"
                min="0"
            >

            <label for="precio_mercado_libre">Precio cuenta Mercado Libre</label>
            <input
                type="number"
                id="precio_mercado_libre"
                name="precio_mercado_libre"
                value="{{ old('precio_mercado_libre', $user->precio_mercado_libre) }}"
                placeholder="Por defecto: precio por cuenta (${{ number_format($user->precio_por_cuenta, 0, '', '.') }})"
                step="0.01"
                min="0"
            >

            <label for="precio_tienda_nube">Precio cuenta Tienda Nube</label>
            <input
                type="number"
                id="precio_tienda_nube"
                name="precio_tienda_nube"
                value="{{ old('precio_tienda_nube', $user->precio_tienda_nube) }}"
                placeholder="Por defecto: precio por cuenta (${{ number_format($user->precio_por_cuenta, 0, '', '.') }})"
                step="0.01"
                min="0"
            >

            <div class="hint">
                Dejá vacío para usar el precio base por cuenta en ese servicio.
            </div>

            <table class="breakdown-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th>Precio unitario</th>
                        <th>Cuentas</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Cuentas base: dueño + empleados --}}
                    <tr class="active-row">
                        <td>Dueño + empleados</td>
                        <td>${{ number_format($user->precio_por_cuenta, 0, '', '.') }}</td>
                        <td>{{ $cuentas_base }}</td>
                        <td>${{ number_format($user->precio_por_cuenta * $cuentas_base, 0, '', '.') }}</td>
                    </tr>

                    {{-- Ecommerce (solo si tiene 'online' seteado) --}}
                    <tr class="{{ $tiene_ecommerce ? 'active-row' : 'inactive-row' }}">
                        <td>
                            Ecommerce
                            @if($tiene_ecommerce)
                                <small>({{ $user->online }})</small>
                                @if($user->precio_ecommerce)
                                    <small class="precio-custom">precio personalizado</small>
                                @endif
                            @else
                                <small>(sin tienda online)</small>
                            @endif
                        </td>
                        <td>${{ number_format($precio_ecommerce_efectivo, 0, '', '.') }}</td>
                        <td>{{ $cuentas_ecommerce }}</td>
                        <td>${{ number_format($precio_ecommerce_efectivo * $cuentas_ecommerce, 0, '', '.') }}</td>
                    </tr>

                    {{-- Mercado Libre (extensión 'mercado_libre') --}}
                    <tr class="{{ $tiene_mercado_libre ? 'active-row' : 'inactive-row' }}">
                        <td>
                            Mercado Libre
                            @if(!$tiene_mercado_libre)
                                <small>(sin extensión)</small>
                            @elseif($user->precio_mercado_libre)
                                <small class="precio-custom">precio personalizado</small>
                            @endif
                        </td>
                        <td>${{ number_format($precio_mercado_libre_efectivo, 0, '', '.') }}</td>
                        <td>{{ $cuentas_mercado_libre }}</td>
                        <td>${{ number_format($precio_mercado_libre_efectivo * $cuentas_mercado_libre, 0, '', '.') }}</td>
                    </tr>

                    {{-- Tienda Nube (extensión 'usa_tienda_nube') --}}
                    <tr class="{{ $tiene_tienda_nube ? 'active-row' : 'inactive-row' }}">
                        <td>
                            Tienda Nube
                            @if(!$tiene_tienda_nube)
                                <small>(sin extensión)</small>
                            @elseif($user->precio_tienda_nube)
                                <small class="precio-custom">precio personalizado</small>
                            @endif
                        </td>
                        <td>${{ number_format($precio_tienda_nube_efectivo, 0, '', '.') }}</td>
                        <td>{{ $cuentas_tienda_nube }}</td>
                        <td>${{ number_format($precio_tienda_nube_efectivo * $cuentas_tienda_nube, 0, '', '.') }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2">Total mensualidad</td>
                        <td>{{ $cuentas_base + $cuentas_ecommerce + $cuentas_mercado_libre + $cuentas_tienda_nube }} cuentas</td>
                        <td>${{ number_format($total_mensualidad, 0, '', '.') }}</td>
                    </tr>
                </tfoot>
            </table>


            <button type="submit">Guardar</button>
        </form>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('payment_expired_at');
    const btn_add = document.getElementById('btn_add_month');
    const btn_sub = document.getElementById('btn_sub_month');

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    // Suma/resta meses cuidando desbordes (ej 31 -> último día del mes destino)
    function add_months(date, months) {
        const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const original_day = d.getDate();

        d.setMonth(d.getMonth() + months);

        // Si cambió el día por overflow, ajusta al último día del mes anterior
        if (d.getDate() !== original_day) {
            d.setDate(0);
        }

        return d;
    }

    function get_input_date() {
        if (!input.value) return null;
        const parts = input.value.split('-').map(Number);
        if (parts.length !== 3) return null;
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function set_input_date(d) {
        const yyyy = d.getFullYear();
        const mm = pad2(d.getMonth() + 1);
        const dd = pad2(d.getDate());
        input.value = `${yyyy}-${mm}-${dd}`;
    }

    btn_add.addEventListener('click', function () {
        let d = get_input_date();
        if (!d) d = new Date();
        set_input_date(add_months(d, 1));
    });

    btn_sub.addEventListener('click', function () {
        let d = get_input_date();
        if (!d) d = new Date();
        set_input_date(add_months(d, -1));
    });
})();
</script>
</body>
</html>
