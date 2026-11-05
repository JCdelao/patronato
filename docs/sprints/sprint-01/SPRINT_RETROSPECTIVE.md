# Sprint 01 — Sprint Retrospective

**Proyecto:** Sistema Integral de Gestión Digital del Patronato (Residenciales)  
**Asignatura:** IS-802 · Ingeniería de Software · UNAH  
**Período analizado:** 19 de octubre al 4 de noviembre de 2026  
**Fecha de corte:** 4 de noviembre de 2026  
**Repositorio:** https://github.com/JCdelao/patronato  
**Tablero:** https://trello.com/b/cGdH1K9N/ingenier%C3%ADa-de-software

> **Naturaleza del documento:** retrospectiva **reconstruida a partir de las incidencias y avances compartidos** durante el sprint, el repositorio y la exportación de Trello. No es una transcripción de una reunión que se haya acreditado como celebrada ni atribuye opiniones individuales. Las acciones del apartado 5 se presentan como **propuestas para validación del equipo**, no como acuerdos ya adoptados.

## 1. Propósito de la retrospectiva

Analizar **cómo trabajamos**, identificar qué nos ayudó y qué nos frenó y proponer cambios concretos para el siguiente sprint. A diferencia del Sprint Review, que inspecciona el producto, la Retrospective inspecciona las **personas, la colaboración, las herramientas y el proceso**. El material de IS-802 la define como el evento donde se analiza el sprint para mejorar en el siguiente ciclo.

## 2. Resumen del período

En este primer ciclo se organizó Patronato con GitHub y Trello; se estableció una arquitectura de monolito modular multitenant; se avanzó en funcionalidades de plataforma, identidad y padrón; y se evolucionó el pipeline de un flujo inicial con limitaciones a workflows separados de CI y CD. Al cierre, Trello ubicaba **8 historias en `Done`, 1 en `Aceptacion`, 2 en `Waiting` y 50 en `Backlog`**. El equipo informó que había realizado el despliegue en Azure.

La actividad técnica coexistió con la preparación de la primera exposición y con la necesidad de corregir observaciones del docente sobre las pruebas automatizadas.

## 3. ¿Qué salió bien? — Mantener

| Observación sustentada | Por qué fue positiva |
|---|---|
| Se establecieron GitHub y Trello desde el inicio. | Permitió centralizar el código, registrar historias de usuario y visualizar estados y responsables. |
| Se definieron arquitectura y tecnologías. | Dio una estructura común para desarrollar Laravel/React/PostgreSQL sin mezclar todas las responsabilidades. |
| Se avanzó en una base funcional de plataforma, identidad y padrón. | Ocho HU quedaron registradas como terminadas en Trello; el sistema dejó de ser únicamente documentación. |
| Se construyeron pruebas funcionales por historia y verificaciones de calidad. | Hizo más identificables los cambios que afectan registro, permisos, auditoría y viviendas, y permitió integrar verificaciones al flujo de GitHub. |
| El CI/CD evolucionó durante el período. | El repositorio pasó a tener workflows separados `ci.yml` y `cd.yml` que automatizan verificaciones y preparan la entrega a Azure. |
| Se revisaron funcionalidades con datos de prueba. | Hay constancia en la tarjeta HU-01 de pruebas de validación de nombres y aislamiento entre dos residenciales. |

## 4. ¿Qué se dificultó? — Corregir o investigar

| Situación observada | Efecto en el trabajo | Aprendizaje |
|---|---|---|
| El pipeline inicial se ejecutaba manualmente y no realizaba un despliegue efectivo. | La primera presentación no pudo mostrar entrega continua completa. | Validar temprano el flujo completo, no solo la existencia de un YAML. |
| El docente observó que hacían falta pruebas unitarias reales. | Una ejecución verde y pruebas de Feature no bastaron para sustentar esa exigencia académica. | Distinguir pruebas unitarias de negocio, funcionales/integración y comprobaciones de infraestructura. |
| La preparación del despliegue en Azure tuvo fallos y restricciones de tiempo/sesión. | Hubo reintentos de configuración y una ejecución de CD fallida antes del despliegue que el equipo comunicó como realizado. | Preparar con anticipación cuentas, permisos, recursos, secretos y un plan de comprobación. |
| Aparecieron dificultades de entorno local con PHP/PostgreSQL. | En un momento, las extensiones `pdo_pgsql`/`pgsql` fueron bloqueadas en un equipo Windows; posteriormente funcionaron en ese mismo equipo. | Documentar versiones, extensiones requeridas y diagnósticos reproducibles sin asumir una causa no confirmada. |
| Algunas HU quedaron cerca del cierre sin cumplir su estado final. | HU-04 quedó en `Aceptacion` y HU-05/HU-09 en `Waiting`. | Separar «código implementado» de «historia aceptada» y verificar todos los criterios de aceptación. |
| El tiempo entre implementación, pruebas y documentación fue ajustado. | La exposición, CI/CD y despliegue exigieron coordinación y revisiones adicionales. | Reservar capacidad para pruebas, integración, evidencia y presentación desde el inicio del sprint. |

**Nota de precisión:** estas situaciones provienen de los registros y conversaciones de desarrollo; **no** representan una encuesta a todos los integrantes ni identifican responsables individuales. Las dificultades de Git local surgidas **después del 4 de noviembre** quedan fuera de este corte.

## 5. Acciones de mejora propuestas para el siguiente sprint

Las medidas siguientes son **propuestas medibles**, pendientes de validación y asignación por el equipo en la siguiente planificación.

| Prioridad | Acción concreta | Resultado verificable | Responsable propuesto |
|---|---|---|---|
| Alta | Definir una pequeña **Definition of Done** por HU: criterios de aceptación, prueba, revisión y evidencia en Trello. | Ninguna tarjeta pasa a `Done` sin checklist completada y evidencia de validación. | Equipo de desarrollo y quien valide historias. |
| Alta | Incorporar **pruebas unitarias de reglas de negocio**, además de las pruebas `Feature` existentes. | Nuevos tests reales en `web/tests/Unit/`, ejecutados correctamente por CI y vinculados a reglas concretas. | Integrante(s) de backend y revisión del equipo. |
| Alta | Documentar el resultado verificable de **CI/CD y Azure** en cada entrega. | Enlace a ejecución verde, identificador de commit y comprobación de la URL/aplicación. | Responsable de despliegue y revisor. |
| Alta | Revisar criterios pendientes de **HU-04, HU-05 y HU-09**, especialmente retención y avisos de respaldo. | Acta de aceptación o motivos explícitos por los que continúan abiertas. | Responsables de las HU y validador. |
| Media | Preparar una **guía reproducible de entorno local**. | Registro de versión de PHP, módulos PostgreSQL, dependencias, pasos de instalación y resolución de errores conocidos. | Equipo técnico. |
| Media | Mejorar la coordinación de cambios en Git. | Trabajo por ramas y pull requests revisados; sin sobrescribir el trabajo de otros ni desplegar por accidente. | Todos los integrantes. |
| Media | Reservar tiempo de sprint para pruebas, documentación y demostración. | En la planificación siguiente aparecen tareas explícitas de validación y evidencia, no solo de desarrollo. | Todo el equipo. |

### Riesgos específicos que conviene aclarar

- **HU-09 y Azure:** la HU pide al menos 30 respaldos diarios conservados y aviso si falla uno; la documentación del repositorio menciona 7 días de retención inicial en Azure. Una restauración exitosa en CI no equivale a satisfacer toda la política de respaldo en producción.
- **Pruebas:** `web/tests/Feature` contiene pruebas por HU, mientras que `web/tests/Unit` no evidencia aún una batería de pruebas unitarias de reglas del negocio. Debe demostrarse la diferencia al docente.
- **Despliegue:** el equipo indicó que Azure ya estaba desplegado, pero para la próxima revisión es conveniente conservar evidencia pública o técnica no sensible de la ejecución satisfactoria.

## 6. ¿Qué mantener, dejar de hacer y comenzar a hacer?

| Mantener | Dejar de hacer | Comenzar a hacer |
|---|---|---|
| Registrar historias y avances en Trello. | Dar por cumplida una HU solo porque exista código o tests relacionados. | Comprobar y adjuntar sus criterios de aceptación antes de moverla a `Done`. |
| Utilizar GitHub y revisión de código. | Tratar una ejecución verde de CI como prueba de que todos los requisitos están completos. | Revisar resultados por tipo de prueba y por historia. |
| Documentar arquitectura y decisiones técnicas. | Preparar el despliegue únicamente cerca de la entrega. | Verificar cuentas, permisos y entorno de publicación al inicio del sprint. |
| Colaboración para resolver bloqueos técnicos. | Depender de explicaciones informales difíciles de repetir. | Registrar soluciones verificadas y procedimientos en `docs/`. |

## 7. Acuerdos y seguimiento

**Estado de acuerdos:** todavía **pendiente de validación formal del equipo**. Para convertir este documento en un acta de retrospectiva, el equipo debe revisar las acciones del apartado 5, elegir responsables reales y registrar en Trello las acciones aceptadas.

**Revisión sugerida:** al comenzar el próximo sprint, comprobar las acciones de prioridad alta y registrar en el Sprint Backlog cuáles se abordarán. En la siguiente retrospectiva, evaluar si disminuyeron los bloqueos de integración y aumentó la evidencia de aceptación y calidad.

## 8. Fuentes y trazabilidad

- Exportación del tablero Trello entregada para el período: https://trello.com/b/cGdH1K9N/ingenier%C3%ADa-de-software
- Versión del proyecto aportada: `.github/workflows/ci.yml`, `.github/workflows/cd.yml`, `docs/pipeline-ci-cd.md`, `web/tests/Feature/`, `web/tests/Unit/`.
- Material académico «IS802 - I Unidad_Semana1-2-3»: **SCRUM – Eventos**, especialmente Sprint Review y Sprint Retrospective.
- Contexto de dificultades reportadas durante la preparación y defensa de la primera entrega: funcionamiento de PHP/PostgreSQL, automatización de pruebas y configuración del despliegue en Azure.

**Conclusión de la retrospectiva:** el equipo logró construir una base organizativa y técnica útil y avanzar en funcionalidades verificables. La mejora prioritaria para el próximo ciclo es **cerrar cada historia con evidencia de aceptación, reforzar pruebas unitarias reales y comprobar oportunamente la publicación**, de forma que el avance técnico se traduzca en entregables demostrables.
