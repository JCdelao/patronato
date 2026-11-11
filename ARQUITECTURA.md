# Arquitectura por capas

El sistema se organiza en cuatro capas, cada una en su carpeta. Cada capa solo usa la que está debajo de ella.

| Capa          | Carpeta          | Qué hace                                                                                      |
| ------------- | ---------------- | --------------------------------------------------------------------------------------------- |
| Presentación  | `presentacion/`  | Muestra las pantallas, recibe las solicitudes, valida los datos y llama a la capa de negocio |
| Negocio       | `negocio/`       | Aplica las reglas del patronato: permisos, cobros, cuotas, auditoría                          |
| Persistencia  | `persistencia/`  | Lee y guarda los datos: modelos y repositorios                                                |
| Base de datos | `base-de-datos/` | Migraciones, seeders, fábricas y scripts de PostgreSQL 18                                     |

`presentacion/` es la aplicación Laravel: ahí están `composer.json`, `package.json`, `artisan`,
las rutas, la configuración y las pruebas. Las otras tres carpetas se cargan desde ella.

El espacio de nombres sigue la carpeta: `negocio/Services/CobroService.php` es
`Patronato\Negocio\Services\CobroService`, y `presentacion/src/Controllers/CobroController.php` es
`Patronato\Presentacion\Controllers\CobroController`.

## Carpetas

**presentacion/**

- `resources/js/`: pantallas de React (`pages/`) y sus componentes.
- `src/Controllers/`: un controlador por pantalla o recurso.
- `src/Requests/`: validación de los formularios.
- `src/Middleware/` y `src/Respuestas/`: filtros de las solicitudes y respuestas del inicio de sesión.
- `routes/`, `config/`, `bootstrap/`, `public/`: rutas y configuración de Laravel.
- `app/Providers/`: conecta las capas con Laravel y Fortify.
- `tests/`: las pruebas de todo el sistema.

**negocio/**

- `Services/`: las reglas de cada funcionalidad.
- `Permisos/`: las estrategias para calcular los permisos de un usuario.
- `Contratos/`: acuerdos entre servicios que todavía no están completos.
- `Acciones/` y `Reglas/`: acciones de Fortify y reglas de contraseña y perfil.
- `Notificaciones/`: correos que envía el sistema.

**persistencia/**

- `Models/`: los modelos de Eloquent, con sus relaciones y el aislamiento por residencial.
- `Repositorios/`: todas las consultas a la base de datos.
- `Transaccion.php`: agrupa operaciones que se guardan todas o ninguna.

**base-de-datos/**

- `migrations/`: tablas, vistas, restricciones e índices.
- `seeders/` y `factories/`: datos iniciales y datos de prueba.
- `scripts/`: SQL para crear el usuario y la base en una máquina nueva.

`scripts/azure/` tiene el arranque del servidor en Azure; no es una capa.

## Reglas

- Presentación llama a los servicios de Negocio. No usa repositorios ni consulta modelos.
- Negocio llama a los repositorios. No escribe consultas ni usa `DB`, `Cache` o `Process`.
- Persistencia no conoce a Negocio ni a Presentación.
- Los modelos se pueden pasar entre capas para leer sus datos, pero solo un repositorio los guarda o los borra.
- Las validaciones `Rule::unique` y `Rule::exists` de los formularios son de Laravel y se pueden usar en Presentación.
- Toda consulta se filtra por `residencial_id`.

`presentacion/tests/Unit/ArquitecturaTest.php` revisa estas reglas en cada ejecución del CI.
