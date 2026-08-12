-- Datos Iniciales para PoliBA (PostgreSQL)

-- 1. Inserción de Roles
INSERT INTO roles (nombre) VALUES 
('Gestor'),
('Administrador'),
('Profesor'),
('Alumno');

-- 2. Inserción de Días
INSERT INTO dias (nombre, orden) VALUES 
('Lunes', 1),
('Martes', 2),
('Miércoles', 3),
('Jueves', 4),
('Viernes', 5),
('Sábado', 6),
('Domingo', 7);

-- 3. Inserción de Categorías de Edad
INSERT INTO categoria (nombre, edad_minima, edad_maxima) VALUES 
('Infantiles', 6, 12),
('Juveniles', 13, 17),
('Mayores', 18, 59),
('Adultos Mayores', 60, 90);

-- 4. Inserción de Polideportivos
INSERT INTO polideportivos (nombre, direccion, horario_apertura, horario_cierre, coordenadas, informacion, imagenURL, fk_dia_apertura, fk_dia_cierre) VALUES 
('Polideportivo Colegiales', 'Freire 234, Colegiales, CABA', '08:00:00', '22:00:00', '-34.5746766,-58.4485984', 'Polideportivo barrial con canchas de voley, futbol, basquet y tenis. Clases gratuitas para todas las edades.', 'colegiales.jpg', 1, 6),
('Polideportivo Parque Sarmiento', 'Av. Dr. Ricardo Balbín 4750, Saavedra, CABA', '07:00:00', '21:00:00', '-34.5539281,-58.5005872', 'Gran parque polideportivo con pistas de atletismo, canchas de futbol profesional, tenis y piscinas.', 'parque_sarmiento.jpg', 2, 7);

-- 5. Inserción de Usuarios de Prueba
-- Contraseña para todos: '123456' (Hash generado con password_hash de PHP utilizando BCRYPT)
-- Hash: $2y$10$H2eMvjK/QoP2k/4Hk0c2Eem2G3JqDkL2Tpx0V6Fp7j52X/98XhBvK

-- Gestor (Administra todo el sistema de polideportivos)
INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol) VALUES 
('Carlos', 'Gestor', '11111111', 'Av. de Mayo 800, CABA', 'gestor@poliba.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 11 4444 4444', '1980-05-15', NULL, 1);

-- Administradores (Uno por Polideportivo)
INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol) VALUES 
('Ana', 'Admin Colegiales', '22222222', 'Zapiola 1500, CABA', 'admin.colegiales@poliba.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 11 5555 5555', '1985-10-20', 1, 2),
('Luis', 'Admin Sarmiento', '33333333', 'Av. Triunvirato 4500, CABA', 'admin.sarmiento@poliba.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 11 6666 6666', '1988-12-05', 2, 2);

-- Profesores
INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol) VALUES 
('Juan', 'Perez', '44444444', 'Av. Cabildo 2000, CABA', 'juan.perez@poliba.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 9 11 5555 1234', '1990-03-12', 1, 3), -- Profesor en Colegiales
('Maria', 'Lopez', '55555555', 'Melián 3000, CABA', 'maria.lopez@poliba.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 9 11 6666 5678', '1992-07-24', 1, 3); -- Profesora en Colegiales

-- Alumnos (Usuarios comunes)
INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol) VALUES 
('Geronimo', 'Giliberti', '45748812', 'Calle falsa 1234, Colegiales, CABA', 'gero@gmail.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 9 1133 5555', '2001-08-15', 1, 4),
('Florencia', 'Torres Nieto', '46123456', 'Amenábar 500, Colegiales, CABA', 'flor@gmail.com', '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq', '+54 9 1144 6666', '2002-11-20', 1, 4);

-- 6. Inserción de Menores (A cargo de Geronimo Giliberti - ID 6)
INSERT INTO menores (nombre, apellido, dni, direccion, fecha_nacimiento, relacion, fk_usuario) VALUES 
('Carlos', 'Giliberti', '55123456', 'Calle falsa 1234, Colegiales, CABA', '2015-05-10', 'Hijo/a', 6);

-- 7. Inserción de Deportes
INSERT INTO deportes (nombre, texto, imagenURL, fk_polideportivo) VALUES 
('Vóley', 'El vóley es un deporte de equipo jugado en una cancha dividida por una red. Ideal para coordinacion y agilidad.', 'voley.jpg', 1),
('Básquet', 'El básquetbol es una disciplina de velocidad, pases rápidos y encestes. Contamos con aros profesionales.', 'basquet.jpg', 1),
('Fútbol 5', 'Disfrutá del fútbol en canchas sintéticas reglamentarias. Alquiler y clases de formación.', 'futbol.jpg', 1),
('Tenis', 'Tenis individual o dobles. Contamos con canchas de polvo de ladrillo y cemento.', 'tenis.jpg', 1),
('Natación', 'Clases de natación en piscina climatizada y libre recreativa.', 'natacion.jpg', 2);

-- 8. Inserción de Canchas
INSERT INTO canchas (nombre, descripcion, imagenURL, techado, fk_polideportivo) VALUES 
('Cancha de Vóley 1', 'Cancha de parquet flotante profesional para vóley.', 'cancha_voley_1.jpg', TRUE, 1),
('Cancha de Vóley 2', 'Cancha exterior de voley sobre cemento.', 'cancha_voley_2.jpg', FALSE, 1),
('Cancha de Básquet 1', 'Cancha reglamentaria de basquet con aros movibles.', 'cancha_basquet.jpg', TRUE, 1),
('Cancha de Fútbol 5', 'Cancha de cesped sintético exterior con iluminación nocturna.', 'cancha_futbol.jpg', FALSE, 1),
('Cancha de Tenis 1', 'Cancha rápida de cemento para tenis.', 'cancha_tenis.jpg', FALSE, 1);

-- 9. Asociación Deportes - Canchas
INSERT INTO deportes_canchas (fk_deporte, fk_cancha) VALUES 
(1, 1), -- Voley en Cancha Voley 1
(1, 2), -- Voley en Cancha Voley 2
(2, 3), -- Basquet en Cancha Basquet
(3, 4), -- Futbol en Cancha Futbol
(4, 5); -- Tenis en Cancha Tenis 1

-- 10. Inserción de Subcategorías (Por edad en Deporte)
INSERT INTO subcategorias (nombre, edad_minima, edad_maxima, fk_deporte, fk_categoria, fk_polideportivo) VALUES 
('Mini Vóley', 6, 12, 1, 1, 1),
('Vóley Juvenil', 13, 17, 1, 2, 1),
('Vóley Mayores A', 18, 40, 1, 3, 1),
('Vóley Recreativo Senior', 60, 80, 1, 4, 1);

-- 11. Inserción de Clases
INSERT INTO clases (nombre, descripcion, horario_inicio, horario_cierre, cupo_maximo, fk_usuario_profesor, fk_deporte, fk_canchas, fk_categoria, fk_subcategoria, fk_polideportivo) VALUES 
('Vóley Mayores Inicial', 'Clase recreativa y técnica de voley para mayores de 18 años.', '18:00:00', '20:00:00', 15, 4, 1, 1, 3, 3, 1),
('Mini Básquet Colegiales', 'Iniciación al basquet para niños y niñas de 6 a 12 años.', '16:00:00', '17:30:00', 2, 5, 2, 3, 1, NULL, 1); -- Cupo bajo para probar listas de espera

-- 12. Asociación Clases - Días
INSERT INTO dias_clases (fk_clase, fk_dia) VALUES 
(1, 1), -- Lunes
(1, 3), -- Miércoles
(2, 2), -- Martes
(2, 4); -- Jueves

-- 13. Inscripciones de Prueba
INSERT INTO inscripcion (fk_clase, fk_usuario, fk_menor, lista_espera, estado) VALUES 
(1, 6, NULL, FALSE, 'activo'), -- Geronimo (ID 6) inscripto a Vóley Mayores Inicial (clase activa)
(2, NULL, 1, FALSE, 'activo'),  -- Menor Carlos (ID 1) inscripto a Mini Básquet (clase activa)
(2, 7, NULL, TRUE, 'activo');   -- Flor (ID 7) inscripta a Mini Básquet en LISTA DE ESPERA (por cupo de 2 alcanzado)

-- 14. Asistencias de Prueba
INSERT INTO asistencia (fk_inscripcion, fk_clase, asistencia, fecha) VALUES 
(1, 1, 'presente', '2026-07-27'), -- Asistencia Lunes pasado
(1, 1, 'presente', '2026-07-29'); -- Asistencia Miércoles pasado

-- 15. Reservas de Cancha de Prueba
INSERT INTO reservas (fk_cancha, fk_usuario, fecha_de_asistencia, horario, estado) VALUES 
(5, 6, '2026-08-01', '18:00:00', 'reservado'), -- Reserva de Tenis 1 por Geronimo para mañana
(4, 7, '2026-08-01', '19:00:00', 'reservado'); -- Reserva de Fútbol 5 por Flor

-- 16. Novedades de Prueba
INSERT INTO novedades (nombre, descripcion, fecha_inicio, fecha_fin, imagenURL, fk_polideportivo) VALUES 
('¡Inscripciones Abiertas 2026!', 'Comenzó el período de inscripción para las actividades deportivas del segundo cuatrimestre. ¡Anotate online!', 'novedad_inscripciones.jpg', '2026-07-01', '2026-08-31', 1),
('Torneo Interno de Vóley Colegiales', 'Este sábado desde las 9 AM se disputará el torneo relámpago mixto. ¡Vení a alentar a tu polideportivo!', 'novedad_torneo.jpg', '2026-07-25', '2026-08-05', 1),
('Mantenimiento Programado en Parque Sarmiento', 'La piscina climatizada permanecerá cerrada por tareas de refacción los días 10 y 11 de agosto.', 'novedad_mantenimiento.jpg', '2026-07-30', '2026-08-12', 2);
