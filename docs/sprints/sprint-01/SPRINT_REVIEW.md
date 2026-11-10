# Sprint 01 — Sprint Review

**Proyecto:** Sistema Integral de Gestión Digital del Patronato (Residenciales)  
**Asignatura:** IS-802 · Ingeniería de Software · UNAH  
**Período del sprint:** 19 de octubre al 4 de noviembre de 2026  
**Fecha de corte de la revisión:** 4 de noviembre de 2026  
**Repositorio:** https://github.com/JCdelao/patronato  
**Tablero:** https://trello.com/b/cGdH1K9N/ingenier%C3%ADa-de-software

> **Alcance de este documento.** Esta revisión se preparó con base en el estado exportado de Trello, la versión del código compartida por el equipo, el material de Scrum de IS-802 y la información proporcionada por los integrantes durante el desarrollo. «Done» significa que la tarjeta figura como terminada en Trello; no sustituye una demostración ni la aprobación formal del Product Owner o del docente. El despliegue a Azure fue informado por el equipo, pero no se adjuntó una URL pública ni el registro final del job de CD para comprobarlo de forma independiente.

## 1. Propósito del Sprint Review

Inspeccionar el **incremento del producto**, mostrar lo implementado, revisar los resultados frente a las historias de usuario y recoger comentarios para ajustar el Product Backlog. En el material de IS-802, el Sprint Review es el evento donde se presenta el incremento a las partes interesadas.

## 2. Objetivo de trabajo del sprint

**Objetivo reconstruido a partir de las tareas y de la primera entrega académica** (no consta un Sprint Goal formal aprobado en los archivos): establecer la organización del proyecto y su arquitectura, desarrollar las funciones base de plataforma e identidad y padrón residencial, y preparar la automatización de pruebas y el despliegue.

El incremento es **parcial**: no representa el sistema completo de cobros, finanzas, seguridad y reportes previsto en las 61 historias de usuario.

## 3. Incremento entregado y evidencias

| Área | Resultado documentado | Evidencia disponible |
|---|---|---|
| Gestión y planificación | Repositorio GitHub y tablero Trello con 61 historias de usuario, responsables y columnas de seguimiento. | Repositorio y exportación del tablero. |
| Arquitectura | Monolito modular con enfoque multitenant, usando `residencial_id` para la separación lógica de información entre residenciales. | `docs/Definición de arquitectura y stacks de tecnologías.pdf`; estructura de `web/src/Modules`. |
| Tecnologías | Backend PHP/Laravel, interfaz React/TypeScript con Inertia.js y base de datos PostgreSQL. | Carpeta `web/`, configuración y migraciones. |
| Funciones del producto | Registro y gestión básica de residenciales, separación de información, usuarios, permisos, auditoría, viviendas y residentes. | Historias HU-01, HU-02, HU-03, HU-06, HU-07, HU-08, HU-10 y HU-11 en `Done`; controladores y pruebas de `web/`. |
| Integración continua | Pipeline de GitHub Actions configurado para `push` a `main`, pull requests y ejecución manual; verificaciones de código, compilación y pruebas por historia, además de migraciones y prueba de restauración en PostgreSQL. | `.github/workflows/ci.yml` y `docs/pipeline-ci-cd.md`. |
| Entrega continua | Workflow separado que publica en Azure App Service después de CI satisfactorio en `main` y comprueba `/up`. | `.github/workflows/cd.yml` y `web/scripts/azure/`. El equipo informó que completó el despliegue; faltaría adjuntar la evidencia de la ejecución final. |

**Precisión de calidad:** en el repositorio existen numerosas **pruebas funcionales (Feature)** por historia de usuario. La carpeta `web/tests/Unit` incluye `ExampleTest.php`, por lo que el número de pruebas de Feature no debe presentarse como evidencia suficiente de pruebas unitarias reales de reglas de negocio. Esta distinción responde a una observación previa del docente.

## 4. Estado del Product Backlog al cierre

La exportación de Trello al 4 de noviembre de 2026 registra **61 historias de usuario** y **una tarea técnica adicional** de arquitectura. La tarjeta «[Plantilla]» no se contabiliza como historia de usuario.

| Estado de Trello | Historias de usuario | Interpretación en esta revisión |
|---|---:|---|
| `Done` | **8** | Marcadas como terminadas por el equipo; deben poder demostrarse. |
| `Aceptacion` | **1** | Implementación pendiente de aceptación/cierre formal en tablero. |
| `Waiting` | **2** | Bloqueadas o pendientes de validación/cierre. |
| `Backlog` | **50** | No forman parte del incremento terminado. |
| **Total** | **61** | 8 + 1 + 2 + 50. |

Además, **[Tarea Técnica] Diseño de arquitectura y tecnologías** aparece en `Done` y no forma parte de las 61 HU.

### Historias en `Done`

| Historia | Funcionalidad | Estado |
|---|---|---|
| [HU-01](https://trello.com/c/slZkOjPk) | Registrar un residencial | Done |
| [HU-02](https://trello.com/c/WbcYNnXf) | Aislar la información de cada residencial | Done |
| [HU-03](https://trello.com/c/0MGBm8hg) | Suspender o reactivar un residencial | Done |
| [HU-06](https://trello.com/c/mTQ5UDLS) | Administrar cuentas de usuario | Done |
| [HU-07](https://trello.com/c/pFNxHsCI) | Configurar permisos por rol | Done |
| [HU-08](https://trello.com/c/B5wddBE7) | Consultar la bitácora de auditoría | Done |
| [HU-10](https://trello.com/c/typUuFqR) | Registrar viviendas (casas y apartamentos) | Done |
| [HU-11](https://trello.com/c/q1XPER16) | Registrar residentes | Done |

### Historias aún no cerradas

| Historia | Funcionalidad | Situación al 4 de noviembre |
|---|---|---|
| [HU-04](https://trello.com/c/9eB2ojAO) | Iniciar sesión | `Aceptacion`: pendiente de aceptación en Trello. |
| [HU-05](https://trello.com/c/CQMrRth0) | Recuperar la contraseña | `Waiting`: pendiente de revisión/cierre, aunque existen pruebas relacionadas en el código. |
| [HU-09](https://trello.com/c/vWsvxn1q) | Respaldar y restaurar la base de datos | `Waiting`: la prueba de respaldo/restauración en CI no demuestra por sí sola toda la política operativa solicitada por la HU. |

La HU-09 pide respaldos diarios con conservación mínima de 30, aviso ante fallos y restauración comprobada en pruebas. La documentación de despliegue describe **7 días de retención inicial en Azure** y una validación de restauración en CI; antes de cerrar HU-09 debe contrastarse la configuración real de Azure con todos sus criterios de aceptación.

## 5. Guion de demostración del incremento

La demostración debe realizarse **en la versión efectivamente disponible**, preferiblemente en un entorno de prueba con datos ficticios. Mostrar el resultado y sus validaciones, sin revelar contraseñas, tokens o datos personales reales.

1. **Plataforma (HU-01 y HU-03).** Registrar un residencial y revisar la opción de suspender/reactivar, si está habilitada para el perfil usado.
2. **Multitenencia (HU-02).** Acceder con usuarios de dos residenciales de prueba y comprobar que la información de uno no se consulta desde el otro. La tarjeta HU-01 incluye un comentario de prueba del aislamiento y de la validación de nombres duplicados.
3. **Identidad y control (HU-06, HU-07, HU-08).** Mostrar administración de usuarios, permisos diferenciados por rol y consulta de auditoría con un perfil autorizado.
4. **Padrón (HU-10 y HU-11).** Registrar una vivienda y un residente, y verificar su relación con el residencial correcto.
5. **Calidad y despliegue.** Abrir GitHub Actions y mostrar la ejecución correspondiente a la revisión presentada: estado de CI, pruebas y CD; abrir el sitio de Azure y comprobar la funcionalidad si está operativo. **No afirmar que el CD está aprobado sin ver la ejecución y la respuesta del sitio.**

## 6. Resultados de la revisión

**Logros principales:** existe una estructura de proyecto común, la arquitectura y el stack están documentados, ocho historias constan en `Done` y se configuraron mecanismos automatizados para verificar el código y preparar la publicación.

**Límites reconocidos:** 53 de las 61 HU no figuran en `Done`; la autenticación requiere aceptación; recuperación de contraseña y respaldos siguen en `Waiting`; hay que presentar evidencia real de pruebas unitarias de lógica de negocio; el despliegue informado debe acompañarse de evidencia verificable y pruebas de funcionamiento.

**Retroalimentación de interesados:** no se dispone de un acta con comentarios formales del Product Owner o del docente **posteriores a esta demostración**. Deben registrarse aquí cuando sean recibidos; no se atribuyen comentarios no documentados.

## 7. Ajustes sugeridos al Product Backlog

Estas son **recomendaciones propuestas para revisión del equipo**, no compromisos ya aprobados:

1. Revisar criterios de aceptación y cerrar HU-04, HU-05 y HU-09 solo cuando exista demostración y evidencia suficiente.
2. Incorporar pruebas unitarias concretas de reglas de negocio, diferenciándolas de pruebas de integración/Feature.
3. Adjuntar enlace o captura de la última ejecución correcta de CI/CD y validar el acceso público a Azure sin exponer credenciales.
4. Priorizar las próximas HU en una reunión de planificación, considerando dependencias entre padrón, cobros y reportes.
5. Conservar evidencias breves por HU en Trello: criterios cumplidos, resultado de prueba y vínculo al código/PR.

## 8. Fuentes y trazabilidad

- Exportación del tablero Trello recibida por el equipo (estado al **4 de noviembre de 2026**): https://trello.com/b/cGdH1K9N/ingenier%C3%ADa-de-software
- Código fuente del ZIP compartido para esta revisión: `.github/workflows/ci.yml`, `.github/workflows/cd.yml`, `docs/pipeline-ci-cd.md`, `web/tests/Feature/` y `web/tests/Unit/`.
- Material de clase «IS802 - I Unidad_Semana1-2-3»: secciones **SCRUM – Eventos** y **SCRUM – Artefactos**.
- Presentación académica de la primera entrega: organización, arquitectura y versión inicial del pipeline. **Las diapositivas describen un estado anterior** al workflow CI/CD separado, por lo que prevalecen el ZIP y Trello recientes para este corte.

**Resultado del Sprint Review:** incremento funcional inicial documentado, con ocho HU señaladas como terminadas en Trello y trabajo relevante pendiente de aceptación, validación y evolución.
