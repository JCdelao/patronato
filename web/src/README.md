# Monolito modular

Cada módulo guarda su código en `Controllers/`, `Services/` y `Models/`.
El espacio de nombres sigue la ruta: `src/Modules/Finanzas/Services/PagoService.php`
es `Patronato\Modules\Finanzas\Services\PagoService`.

| Módulo     | Épicas    | Tablas principales                                                                                         |
| ---------- | --------- | ---------------------------------------------------------------------------------------------------------- |
| Plataforma | E01       | `residenciales`, `configuracion_residencial`                                                               |
| Identidad  | E02       | `users`, `roles`, `rol_permisos`, `auditoria`                                                              |
| Padron     | E03, E09  | `viviendas`, `residentes`, `vehiculos`, `stickers`, `carnets`                                              |
| Finanzas   | E04 a E08 | `conceptos_cobro`, `tarifas`, `cargos`, `cajas`, `pagos`, `ajustes`, `egresos`, `ingresos`, `presupuestos` |
| Seguridad  | E10 a E12 | `guardias`, `turnos`, `incidencias`, `visitas`, `preautorizaciones`, `qr_residentes`, `escaneos_qr`        |
| Portal     | E13       | `solicitudes_correccion`                                                                                   |
| Reportes   | E14       | Solo lectura de los demás módulos                                                                          |
| Migracion  | E15       | `lotes_migracion`, `migracion_errores`                                                                     |

## Reglas

- Un módulo usa a otro a través de sus `Services`, no de sus `Models` ni `Controllers`.
- Lo que usan varios módulos va en `Shared/`.
- Toda consulta se filtra por `residencial_id`.
