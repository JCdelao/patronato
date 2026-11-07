# Cambios opcionales

Mejoras que se pueden hacer más adelante. No son urgentes y el sistema funciona sin ellas.

## Bitácora: leer los nombres a través de los Services de cada módulo

**Situación actual:** para mostrar nombres en la bitácora en lugar de ids (por ejemplo "Vivienda: A-01" en vez de `viviendas #5`), `BitacoraService` lee directamente las tablas `users`, `roles`, `viviendas`, `residentes` y `residenciales` con `DB::table`. Solo lee, no modifica nada.

**Por qué es opcional:** la regla de la arquitectura dice que un módulo usa a otro a través de sus `Services`. Identidad está leyendo tablas de Padron y Plataforma sin pasar por ellos.

**Cómo hacerlo:**

1. Que cada módulo tenga un método que devuelva los nombres de sus registros, por ejemplo `ViviendaService::nombres(array $ids): array` y `ResidenteService::nombres(array $ids): array`.
2. Que `BitacoraService` llame a esos métodos en lugar de usar `DB::table`.
3. Mantener la prueba `the log shows names instead of ids` de `tests/Feature/Identidad/BitacoraTest.php`; debe seguir pasando sin cambios.

**Archivo afectado:** `web/src/Modules/Identidad/Services/BitacoraService.php`, método `nombres()`.
