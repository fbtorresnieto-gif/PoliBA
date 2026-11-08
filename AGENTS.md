# Development Efficiency Harness — MultiPro v1.1

Estas instrucciones complementan el `AGENTS.md` global. Si existe conflicto, prevalecen las instrucciones de mayor prioridad y las indicaciones expresas del usuario.

## Alcance y checkout

- Checkout vigente de desarrollo: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA`.
- `C:\MultiPro\MultiPro` es un checkout histórico: sirve como referencia documental, no como base para implementar ni desplegar.
- Antes de actuar, informar ruta, rama, `git status --short --branch`, ambiente objetivo y alcance autorizado.
- Distinguir siempre **Local**, **Staging** y **Producción**. Una autorización para un ambiente no se extiende a otro.
- Las referencias Git locales no prueban qué commit está desplegado. Railway, Cron y migraciones efectivas requieren verificación independiente y de sólo lectura antes de afirmarlos como actuales.

## Exploración eficiente

1. Leer este archivo y, según la tarea, `docs/ARCHITECTURE.md`, `docs/TESTING.md` y `docs/DECISIONS.md`.
2. Buscar primero por nombre y patrón con `rg --files` y `rg`; abrir sólo entradas, servicios, migraciones y pruebas relacionadas.
3. No recorrer `prototipo/`, `outputs/`, catálogos completos, binarios ni documentos extensos salvo necesidad concreta.
4. Consultar Git por metadatos o diffs acotados antes de comparar árboles completos.
5. No releer archivos sin cambios ni repetir una prueba sin una hipótesis nueva.
6. Tras dos intentos fallidos de la misma estrategia, detenerla, registrar evidencia y replantear la causa.
7. Evitar subagentes en tareas triviales; usarlos sólo para trabajos independientes cuyo ahorro supere el coste de coordinación.

## Presupuesto operativo por tarea

Antes de una tarea de desarrollo, clasificarla como **baja**, **media** o **alta** y declarar brevemente:

- objetivo y criterio de aceptación;
- archivos o módulos inicialmente relevantes;
- validación mínima necesaria;
- riesgos y dependencias conocidos.

Presupuestos iniciales orientativos de inspección de código:

- **Baja:** hasta 5 archivos de código.
- **Media:** hasta 12 archivos de código.
- **Alta:** exploración por etapas, con alcance acordado.

Son umbrales de revisión, no límites absolutos. No cuentan como archivos de código las instrucciones, documentación o resultados breves consultados para orientar la tarea; sí deben contarse los archivos fuente, scripts, migraciones y pruebas inspeccionados directamente.

Al alcanzar o superar el presupuesto, antes de una ampliación sustancial:

1. explicar por qué la evidencia actual no alcanza;
2. identificar los archivos o módulos adicionales a revisar;
3. solicitar confirmación para ampliar sustancialmente el alcance.

No pedir autorización por cada archivo o comando ni detener validaciones imprescindibles para seguridad o integridad. Tras dos intentos fallidos de la misma estrategia, detenerla, distinguir el fallo de una búsqueda normal y replantear la hipótesis antes de continuar.

## Límites de cambio

- Hacer cambios mínimos; no refactorizar módulos ajenos ni cambiar dependencias sin autorización.
- Preservar rutas, contratos API, roles, estados, historia y reglas de negocio existentes.
- Usar **MultiPro** en texto nuevo. No reemplazar ciegamente identificadores históricos, rutas, tablas, remotos o historial.
- No sobrescribir cambios del usuario ni archivos privados. Nunca registrar secretos, valores de entorno o datos personales.
- Commit, push, merge, checkout, despliegue, configuración remota y cambios productivos requieren alcance o autorización explícitos.
- Toda migración debe identificar destino, precondiciones, transacción, permisos, validación y recuperación. Probar primero en una copia temporal; nunca inferir autorización productiva.
- Operaciones destructivas, reembolsos reales, envíos reales y cambios de Cron requieren confirmación específica.

## Validación proporcional

Consultar `docs/TESTING.md` y empezar por la prueba focalizada:

- **PHP web:** `php -l` en cada PHP modificado; luego flujo HTTP o suite del módulo.
- **API:** contrato, autenticación, autorización por propietario/entidad, códigos HTTP, idempotencia y ausencia de campos internos.
- **Android:** prueba unitaria y ensamblado del build correspondiente; instrumentación sólo con dispositivo/emulador y backend controlado.
- **PostgreSQL:** aplicar migraciones y rollback sólo en base temporal; verificar datos, restricciones, permisos, concurrencia e idempotencia.
- **Pagos:** cubrir gratuito y presencial, importe congelado, titularidad, cobro, cancelación, devolución, concurrencia y bloqueo online. No simular como evidencia de Mercado Pago real.
- **Correo:** mantener envío deshabilitado en Local salvo prueba autorizada; validar colas, reintentos e idempotencia sin exponer destinatarios ni tokens.
- **Cron:** validar el procesador CLI y las colas por separado. El éxito del proceso no prueba entrega del proveedor ni que el Cron remoto esté activo.

No afirmar que una entrega funciona si no se ejecutó la validación pertinente. Informar comando, ambiente, resultado y límite de la evidencia.

## Entrega

Resumir: archivos modificados, comportamiento, pruebas ejecutadas, pruebas no ejecutadas, riesgos y estado Git. Mantener documentación breve y enlazar las fuentes existentes en vez de duplicarlas.

Cerrar cada tarea con este bloque, usando sólo métricas observables:

```text
DEH — Eficiencia
- Complejidad:
- Archivos de código inspeccionados:
- Archivos modificados:
- Comandos ejecutados:
- Reintentos por fallo:
- Pruebas y resultados:
- Ampliaciones de alcance:
- Estado: completado/parcial/bloqueado.
```

No inventar métricas. Informar `N/D` cuando un dato no pueda obtenerse de forma confiable y no contar búsquedas normales como reintentos fallidos. El harness no mide por sí mismo tokens, créditos ni cuota Plus; sólo registrar esos valores cuando exista una fuente real y explícita.
