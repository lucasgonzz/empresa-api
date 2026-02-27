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
            </div>

            <p>
                 {{ count($user->employees) +1 }} usuarios (contando al dueño y todos los empleados)
            </p>
            <h3>
                Total mensualidad: ${{ number_format($user->precio_por_cuenta * (count($user->employees) + 1), 0, '', '.' )}}
            </h3>


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
