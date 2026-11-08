# Monitor local de eficiencia DEH

Registra mediciones manuales por tarea sin consultar sesiones de Codex, datos privados ni servicios externos. Los porcentajes deben copiarse manualmente desde la interfaz de ChatGPT.

## Archivos

- `tasks.csv`: registro local de tareas.
- `measure.ps1`: inicio, cierre y listado.

El CSV guarda identificación, fechas, descripción, complejidad, modelo, razonamiento, porcentajes antes/después, consumo, duración, reintentos, pruebas y resultado.

## Uso

Desde la raíz del repositorio:

```powershell
& .\.deh\measure.ps1 -Action Start
```

El comando devuelve un ID. Para cerrar la tarea:

```powershell
& .\.deh\measure.ps1 -Action Close -Id 'DEH-AAAAMMDD-HHMMSS-xxxxxx'
```

Si se omite `-Id`, muestra las tareas abiertas y lo solicita. Para listar el registro:

```powershell
& .\.deh\measure.ps1 -Action List
```

Se puede ejecutar sin `-Action` para elegirla manualmente.

## Porcentajes y comparabilidad

- Ingresar valores de 0 a 100. Se aceptan punto o coma decimal.
- Usar `Enter`, `N/D`, `NA` o `N/A` cuando el valor no esté disponible.
- El consumo se calcula como `disponible antes - disponible después`.
- Si cualquiera de los porcentajes aumenta, se presume un restablecimiento de cuota entre mediciones.
- Ante restablecimiento o datos faltantes, `Comparable` queda en `No` y no se calculan consumos, evitando interpretar una recarga como ahorro.

## Alcance y privacidad

- El script sólo lee y escribe el CSV indicado; por defecto, `.deh/tasks.csv`.
- No inspecciona la aplicación ChatGPT, sesiones, historial, archivos privados ni variables de entorno.
- No usa red, APIs ni autenticación.
- Registrar descripciones y pruebas sin credenciales, destinatarios, tokens ni datos personales.
- Los porcentajes miden consumo observado, no calidad ni causalidad. Comparar tareas semejantes y conservar el resultado de las pruebas.
