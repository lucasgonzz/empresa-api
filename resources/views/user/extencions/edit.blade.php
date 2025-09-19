<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurador de extenciones</title>
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
        }

        input[type="checkbox"] {
            margin-right: 10px;
        }

        button {
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

        button:hover {
            background-color: #0056b3;
        }

        .status {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-box">
        <h1>Extenciones de {{ $user->name }}</h1>

        @if(session('success'))
            <p class="status">{{ session('success') }}</p>
        @endif

        <form method="POST" action="{{ route('users.extencions.update', $user->id) }}">
            @csrf

            @foreach ($extencions as $extencion)
                <label>
                    <input
                        type="checkbox"
                        name="extencions[]"
                        value="{{ $extencion->id }}"
                        {{ in_array($extencion->id, $user_extencion_ids) ? 'checked' : '' }}
                    >
                    {{ $extencion->name }}
                </label>
            @endforeach

            <button type="submit">Guardar extenciones</button>
        </form>
    </div>
</div>
</body>
</html>
