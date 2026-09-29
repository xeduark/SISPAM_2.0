# SISPAM_2 - Sistema de Dispensación de Medicamentos

## Stack
- Laravel 12 + Livewire 3 + Filament 3 + MySQL

## Convenciones
- Código de negocio en español, estructura Laravel en inglés
- Commits en español
- Mensajes de error y UI en español (usuarios colombianos)

## Estilos visuales
- Toda la UI sigue SIEMPRE `estilos_generador_consolidado.md` (raíz del proyecto):
  paleta institucional, Arial, tema claro/oscuro, radios, sombras, encabezado institucional.
- Implementación en Filament (sin build de Tailwind):
  - Paletas `primary` (azul institucional) y `gray` en `AdminPanelProvider` (tono 600 = claro, 500 = oscuro)
  - Sombras, radios, encabezados de tabla y marca en `public/css/sispam-tema.css`
  - Encabezado institucional en `resources/views/filament/marca.blade.php`
- Páginas o componentes nuevos reutilizan esas variables; no introducir colores ni fuentes fuera de la guía.

## Cómo correr el proyecto localmente
1. Tener XAMPP corriendo (solo MySQL, Apache no es necesario)
2. Crear la base de datos sispam_2 en phpMyAdmin
3. Copiar .env.example a .env y configurar credenciales
4. Ejecutar:
```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
5. Abrir http://localhost:8000/admin

## Autenticación (Authentik / OIDC)
- No hay login local ni contraseñas: `/admin/login` solo tiene el botón "Iniciar sesión", que redirige a Authentik.
- Flujo: `routes/web.php` → `App\Http\Controllers\Auth\AuthentikController` (Socialite + `socialiteproviders/authentik`).
- El `preferred_username` (username) de Authentik ES el documento: se busca en `users.documento`.
  Solo entran usuarios creados antes en el recurso Usuarios y con `activo = true`; los demás se rechazan.
- Cerrar sesión redirige al end-session de Authentik (`App\Http\Responses\LogoutResponse`).
- Variables `.env`: `AUTHENTIK_BASE_URL`, `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET`,
  `AUTHENTIK_REDIRECT_URI="${APP_URL}/auth/authentik/callback"`, `AUTHENTIK_APP_SLUG`.

## Acceso local
- URL: http://localhost:8000/admin
- Usuario administrador sembrado: documento `AdminSispam` (debe existir con ese username en Authentik; la contraseña se gestiona allá)

## Base de datos
- Nombre: sispam_2
- Motor: MySQL (XAMPP)
