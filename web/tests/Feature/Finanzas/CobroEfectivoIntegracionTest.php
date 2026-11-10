<?php

/*
 * Pruebas de integración del cobro en efectivo que dependen de otras funcionalidades
 *
 * Están marcadas como pendientes (todo): se listan al correr las pruebas, pero no se
 * ejecutan ni fallan. Se completan cuando se integren la generación de cargos y la
 * apertura y el cierre de caja. No están en el CI hasta entonces.
 *
 * Comprobación manual pendiente en PostgreSQL (no es una prueba automatizada)
 *
 * SQLite no permite probar el bloqueo de la numeración de recibos, por eso se
 * comprueba a mano cuando existan los cargos y la apertura de caja:
 * 1. Preparar un residencial con una vivienda con dos meses pendientes y dos
 *    cobradores, cada uno con su caja abierta.
 * 2. Registrar al mismo tiempo un cobro de cada cobrador, desde dos sesiones.
 * 3. Resultado esperado: los dos pagos se guardan con recibos distintos y
 *    consecutivos, y no aparece ningún error del índice único de recibos.
 */

describe('pending integration with monthly charge generation', function () {
    test('pending months come from the real charges of the housing unit')
        ->todo(note: 'Requiere el servicio real de CargosPendientes registrado en AppServiceProvider');

    test('after a partial payment the month shows only the remaining amount')
        ->todo(note: 'Requiere que el pendiente descuente las aplicaciones de pagos confirmados');

    test('a fully paid month no longer appears as pending')
        ->todo(note: 'Requiere el servicio real de CargosPendientes');
});

describe('pending integration with opening and closing the cash box', function () {
    test('a payment goes into the box opened by the treasurer')
        ->todo(note: 'Requiere la apertura de caja por el tesorero');

    test('a payment cannot enter a box while it is being closed')
        ->todo(note: 'El cierre debe bloquear la caja con lockForUpdate igual que el cobro');

    test('payments of a closed box keep their box and cannot be edited')
        ->todo(note: 'Requiere el cierre de caja');
});
