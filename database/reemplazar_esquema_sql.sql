-- Uso único: quita lo que creó el script SQL anterior para que las migraciones de Laravel lo creen de nuevo.
-- Conserva las tablas de Laravel y las cuentas de users.
-- Se detiene sin cambiar nada si ya hay residenciales registrados.
-- Después de ejecutarlo, en web/: php artisan migrate
--                                 php artisan db:seed --class=RolesSeeder

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM residenciales) THEN
        RAISE EXCEPTION 'Hay residenciales registrados: no se quita el esquema';
    END IF;
END;
$$;

DROP VIEW v_saldos_vivienda;

DROP TABLE
    solicitudes_correccion, excepciones_acceso, escaneos_qr, qr_residentes, visitas,
    autorizado_frecuente_viviendas, autorizados_frecuentes, preautorizaciones, medios_entrada,
    incidencias, tipos_incidencia, entregas_turno, turnos, guardias,
    carnets, stickers, vehiculo_conductores, vehiculos,
    presupuestos, ingresos, egresos, categorias_financieras, ajustes, pago_aplicaciones,
    pagos, cajas, cargos, tarifas, conceptos_cobro, modalidades_cobro,
    vivienda_residentes, residentes, viviendas,
    migracion_errores, lotes_migracion,
    auditoria, rol_permisos, configuracion_residencial;

DROP FUNCTION auditoria_inmutable();

ALTER TABLE users
    DROP COLUMN residencial_id,
    DROP COLUMN rol_id,
    DROP COLUMN activo,
    DROP COLUMN debe_cambiar_password;

DROP TABLE roles, residenciales;

COMMIT;
