# Tests de compras (`@group compras`)

## 1. Reconstruir la base de testing y sembrar el fixture

```
php artisan migrate:fresh --env=testing
php artisan db:seed --env=testing --class="Database\Seeders\testing\TestingFerreteriaSeeder"
```

(Requiere `.env.testing` copiado de `.env.testing.example`, con `DB_DATABASE` conteniendo
`testing`, ej. `empresa_testing`.)

## 2. Correr la suite

```
vendor\bin\phpunit --group compras
```
