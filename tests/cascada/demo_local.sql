-- Solo la copia de staging local. Datos ficticios para revisar filtros sin inventar ubicaciones históricas.
BEGIN;
INSERT INTO provincias(nombre) VALUES('Buenos Aires'),('Córdoba');
INSERT INTO localidades(fk_provincia,nombre) SELECT id,'La Plata' FROM provincias WHERE nombre='Buenos Aires';
INSERT INTO localidades(fk_provincia,nombre) SELECT id,'Mar del Plata' FROM provincias WHERE nombre='Buenos Aires';
INSERT INTO localidades(fk_provincia,nombre) SELECT id,'Córdoba' FROM provincias WHERE nombre='Córdoba';
INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,informacion,fk_localidad,fk_dia_apertura,fk_dia_cierre)
SELECT 'DEMO · '||l.nombre,'Dirección ficticia para pruebas','08:00','22:00','Entidad ficticia de revisión local; no representa una institución real.',l.id,
    (SELECT id FROM dias WHERE orden=1 LIMIT 1),(SELECT id FROM dias WHERE orden=7 LIMIT 1) FROM localidades l;
INSERT INTO actividades(nombre,texto,fk_entidad)
SELECT 'Actividad DEMO · '||nombre,'Actividad ficticia para revisar filtros.',id FROM entidades WHERE nombre LIKE 'DEMO · %';
INSERT INTO espacios(nombre,descripcion,fk_entidad)
SELECT 'Espacio DEMO · '||nombre,'Espacio gratuito ficticio para revisar filtros y reservas locales.',id FROM entidades WHERE nombre LIKE 'DEMO · %';
COMMIT;
