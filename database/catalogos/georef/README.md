# Catálogo oficial Georef para MultiPro

Fuente: [Georef — Argentina.gob.ar](https://www.argentina.gob.ar/georef), con [descarga completa documentada](https://www.argentina.gob.ar/georef/descarga-de-la-base-completa) y [definición de localidades](https://www.argentina.gob.ar/georef/localidades-y-asentamientos).

La copia descargada el 30/10/2026 contiene 24 jurisdicciones (incluida la Ciudad Autónoma de Buenos Aires) y 4.028 localidades del recurso `localidades` de la API v2.0. Se conservan código oficial, nombre, provincia, departamento y categoría. No es el recurso de municipios ni el de localidades censales: Georef distingue esos conceptos.

`manifest.json` identifica las URL consultadas, fecha de descarga, cantidades y hashes SHA-256 de la respuesta y de los archivos reducidos que se importan. La fecha de descarga no afirma una fecha de actualización del organismo. Los archivos se guardan en UTF-8 y los códigos en texto para conservar ceros iniciales.

La migración 010 agrega metadatos de referencia a las tablas existentes. El importador `CatalogoGeoref` valida los archivos antes de escribir y realiza la carga por lotes en una única transacción. Repetirlo conserva los IDs locales y no duplica el catálogo. No elimina registros ausentes de una descarga ni reasigna entidades.

Los nombres homónimos no son una clave: el catálogo descargado presenta 154 grupos de nombres repetidos dentro de una provincia. Se distinguen por código oficial y se muestran departamento y categoría. Una ubicación manual se vincula con código oficial únicamente si su coincidencia exacta de nombre/provincia es única; las coincidencias ambiguas se conservan manuales para revisión del Gestor.

Estado comprobado en `multipro_staging_filtros_cascada`: 24 provincias/jurisdicciones oficiales, 4.028 localidades oficiales y 3 localidades manuales conservadas; cero localidades sin provincia. Entidades, reservas e inscripciones conservaron sus datos y vínculos. El respaldo previo fue restaurado y comparado antes de importar.

Al crear o editar una entidad, el Gestor elige provincia y localidad del catálogo local, con búsqueda por nombre o departamento. Se conserva una excepción explícita para cargar una ubicación no encontrada, sin atribuirle un código oficial. Los filtros públicos muestran las ubicaciones de entidades activas disponibles, no las 4.028 localidades sin oferta.

La aplicación no consulta Georef al usar el formulario: solo utiliza la copia local. Las actualizaciones del catálogo son explícitas; no hay sincronización programada ni importación automática hacia Productivo.

Comprobador local aislado:

```powershell
pwsh -NoProfile -File tests/filtros/validar_georef_local.ps1
```

Descarga explícita de una futura versión, para revisar antes de importar:

```powershell
python tests/filtros/descargar_georef.py
```

El script `tests/filtros/aplicar_georef_local.ps1` solo admite la copia local configurada, crea respaldo y verifica restauración antes de cargar. No utilizarlo como procedimiento productivo. La publicación y el plan de migración productiva siguen pendientes de autorización separada.
