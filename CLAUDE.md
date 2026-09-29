# SISPAM_2 - Sistema de Dispensación de Medicamentos

## Stack
- Laravel 12 + Livewire 3 + Filament 3 + MySQL

## Convenciones
- Código de negocio en español, estructura Laravel en inglés
- Commits en español
- Mensajes de error y UI en español (usuarios colombianos)

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

## Acceso local
- URL: http://localhost:8000/admin
- Email: admin@sispam.com
- Password: Sispam2026*

## Base de datos
- Nombre: sispam_2
- Motor: MySQL (XAMPP)
