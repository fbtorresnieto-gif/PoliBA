-- Esquema de Base de Datos para PoliBA (PostgreSQL)

-- 1. Tabla de Roles
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE
);

-- 2. Tabla de Dias de la semana
CREATE TABLE dias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(20) NOT NULL UNIQUE,
    orden INT NOT NULL
);

-- 3. Tabla de Polideportivos
CREATE TABLE polideportivos (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    horario_apertura TIME NOT NULL,
    horario_cierre TIME NOT NULL,
    coordenadas VARCHAR(100), -- Formato 'lat,lng' para MapLibre
    informacion TEXT,
    imagenURL VARCHAR(255),
    fk_dia_apertura INT REFERENCES dias(id),
    fk_dia_cierre INT REFERENCES dias(id),
    estado BOOLEAN DEFAULT TRUE
);

-- 4. Tabla de Usuarios (Gestores, Administradores, Profesores, Alumnos)
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) UNIQUE NOT NULL,
    direccion VARCHAR(255),
    email VARCHAR(150) UNIQUE NOT NULL,
    contrasena VARCHAR(255) NOT NULL,
    telefono VARCHAR(50),
    fecha_nacimiento DATE,
    fk_polideportivo INT REFERENCES polideportivos(id) ON DELETE SET NULL,
    fk_rol INT NOT NULL REFERENCES roles(id)
);

-- 5. Tabla de Menores (asociados a un Usuario Alumno mayor)
CREATE TABLE menores (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) UNIQUE NOT NULL,
    direccion VARCHAR(255),
    fecha_nacimiento DATE NOT NULL,
    relacion VARCHAR(50) NOT NULL, -- Ej: 'Hijo/a', 'Nieto/a', 'Tutorado/a'
    fk_usuario INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 6. Tabla de Novedades
CREATE TABLE novedades (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    imagenURL VARCHAR(255),
    fk_polideportivo INT REFERENCES polideportivos(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 7. Tabla de Deportes
CREATE TABLE deportes (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    texto TEXT,
    imagenURL VARCHAR(255),
    fk_polideportivo INT REFERENCES polideportivos(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 8. Tabla de Canchas
CREATE TABLE canchas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    imagenURL VARCHAR(255),
    techado BOOLEAN DEFAULT FALSE,
    fk_polideportivo INT REFERENCES polideportivos(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 9. Tabla de Relación Deportes y Canchas (Muchos a Muchos)
CREATE TABLE deportes_canchas (
    id SERIAL PRIMARY KEY,
    fk_deporte INT NOT NULL REFERENCES deportes(id) ON DELETE CASCADE,
    fk_cancha INT NOT NULL REFERENCES canchas(id) ON DELETE CASCADE,
    CONSTRAINT uq_deporte_cancha UNIQUE (fk_deporte, fk_cancha)
);

-- 10. Tabla de Categorias de Edad (Generales)
CREATE TABLE categoria (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    edad_minima INT NOT NULL,
    edad_maxima INT NOT NULL
);

-- 11. Tabla de Subcategorias (Rangos acotados para clases por deporte/polideportivo)
CREATE TABLE subcategorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    edad_minima INT NOT NULL,
    edad_maxima INT NOT NULL,
    fk_deporte INT NOT NULL REFERENCES deportes(id) ON DELETE CASCADE,
    fk_categoria INT REFERENCES categoria(id) ON DELETE SET NULL,
    fk_polideportivo INT NOT NULL REFERENCES polideportivos(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 12. Tabla de Clases
CREATE TABLE clases (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    horario_inicio TIME NOT NULL,
    horario_cierre TIME NOT NULL,
    cupo_maximo INT NOT NULL,
    fk_usuario_profesor INT REFERENCES usuarios(id) ON DELETE SET NULL,
    fk_deporte INT NOT NULL REFERENCES deportes(id) ON DELETE CASCADE,
    fk_canchas INT REFERENCES canchas(id) ON DELETE SET NULL,
    fk_categoria INT REFERENCES categoria(id) ON DELETE SET NULL,
    fk_subcategoria INT REFERENCES subcategorias(id) ON DELETE SET NULL,
    fk_polideportivo INT NOT NULL REFERENCES polideportivos(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 13. Tabla de Relación Clases y Dias (Muchos a Muchos)
CREATE TABLE dias_clases (
    id SERIAL PRIMARY KEY,
    fk_clase INT NOT NULL REFERENCES clases(id) ON DELETE CASCADE,
    fk_dia INT NOT NULL REFERENCES dias(id) ON DELETE CASCADE,
    CONSTRAINT uq_clase_dia UNIQUE (fk_clase, fk_dia)
);

-- 14. Tabla de Inscripciones a Clases
CREATE TABLE inscripcion (
    id SERIAL PRIMARY KEY,
    fk_clase INT NOT NULL REFERENCES clases(id) ON DELETE CASCADE,
    fk_usuario INT REFERENCES usuarios(id) ON DELETE CASCADE, -- Alumno Mayor
    fk_menor INT REFERENCES menores(id) ON DELETE CASCADE,     -- O Alumno Menor (uno de los dos debe ser no nulo)
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    lista_espera BOOLEAN DEFAULT FALSE, -- TRUE si no alcanzó cupo
    estado VARCHAR(20) DEFAULT 'activo', -- 'activo', 'cancelado' (por el alumno)
    CONSTRAINT chk_inscrito CHECK (
        (fk_usuario IS NOT NULL AND fk_menor IS NULL) OR 
        (fk_usuario IS NULL AND fk_menor IS NOT NULL)
    )
);

-- 15. Tabla de Asistencias (Toma de asistencia por clase)
CREATE TABLE asistencia (
    id SERIAL PRIMARY KEY,
    fk_inscripcion INT NOT NULL REFERENCES inscripcion(id) ON DELETE CASCADE,
    fk_clase INT NOT NULL REFERENCES clases(id) ON DELETE CASCADE,
    asistencia VARCHAR(20) NOT NULL, -- 'presente', 'ausente', 'lluvia'
    fecha DATE NOT NULL,
    CONSTRAINT uq_asistencia_inscripcion_fecha UNIQUE (fk_inscripcion, fecha)
);

-- 16. Tabla de Reservas de Canchas
CREATE TABLE reservas (
    id SERIAL PRIMARY KEY,
    fk_cancha INT NOT NULL REFERENCES canchas(id) ON DELETE CASCADE,
    fk_usuario INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE, -- Alumno reservante
    fecha_de_asistencia DATE NOT NULL,
    horario TIME NOT NULL,
    estado VARCHAR(20) DEFAULT 'reservado', -- 'reservado', 'cancelado'
    CONSTRAINT uq_reserva_cancha_fecha_hora UNIQUE (fk_cancha, fecha_de_asistencia, horario)
);
