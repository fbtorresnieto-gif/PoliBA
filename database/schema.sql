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

-- 3. Tabla de Entidades
CREATE TABLE entidades (
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
    fk_entidad INT REFERENCES entidades(id) ON DELETE SET NULL,
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
    fk_entidad INT REFERENCES entidades(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 7. Tabla de Actividades
CREATE TABLE actividades (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    texto TEXT,
    imagenURL VARCHAR(255),
    fk_entidad INT REFERENCES entidades(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 8. Tabla de Espacios
CREATE TABLE espacios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    imagenURL VARCHAR(255),
    techado BOOLEAN DEFAULT FALSE,
    fk_entidad INT REFERENCES entidades(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 9. Tabla de Categorias de Edad (Generales)
CREATE TABLE categoria (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    edad_minima INT NOT NULL,
    edad_maxima INT NOT NULL
);

-- 10. Tabla de Subcategorias (Rangos acotados para modulos por actividad/entidad)
CREATE TABLE subcategorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    edad_minima INT NOT NULL,
    edad_maxima INT NOT NULL,
    fk_actividad INT NOT NULL REFERENCES actividades(id) ON DELETE CASCADE,
    fk_categoria INT REFERENCES categoria(id) ON DELETE SET NULL,
    fk_entidad INT NOT NULL REFERENCES entidades(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 11. Tabla de Modulos
CREATE TABLE modulos (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    horario_inicio TIME NOT NULL,
    horario_cierre TIME NOT NULL,
    cupo_maximo INT NOT NULL,
    fk_usuario_profesor INT REFERENCES usuarios(id) ON DELETE SET NULL,
    fk_actividad INT NOT NULL REFERENCES actividades(id) ON DELETE CASCADE,
    fk_espacio INT REFERENCES espacios(id) ON DELETE SET NULL,
    fk_categoria INT REFERENCES categoria(id) ON DELETE SET NULL,
    fk_subcategoria INT REFERENCES subcategorias(id) ON DELETE SET NULL,
    fk_entidad INT NOT NULL REFERENCES entidades(id) ON DELETE CASCADE,
    estado BOOLEAN DEFAULT TRUE
);

-- 12. Tabla de Relación Modulos y Dias (Muchos a Muchos)
CREATE TABLE dias_modulos (
    id SERIAL PRIMARY KEY,
    fk_modulo INT NOT NULL REFERENCES modulos(id) ON DELETE CASCADE,
    fk_dia INT NOT NULL REFERENCES dias(id) ON DELETE CASCADE,
    CONSTRAINT uq_clase_dia UNIQUE (fk_modulo, fk_dia)
);

-- 13. Tabla de Inscripciones a Modulos
CREATE TABLE inscripcion (
    id SERIAL PRIMARY KEY,
    fk_modulo INT NOT NULL REFERENCES modulos(id) ON DELETE CASCADE,
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

-- 14. Tabla de Asistencias (Toma de asistencia por modulo)
CREATE TABLE asistencia (
    id SERIAL PRIMARY KEY,
    fk_inscripcion INT NOT NULL REFERENCES inscripcion(id) ON DELETE CASCADE,
    fk_modulo INT NOT NULL REFERENCES modulos(id) ON DELETE CASCADE,
    asistencia VARCHAR(20) NOT NULL, -- 'presente', 'ausente', 'lluvia'
    fecha DATE NOT NULL,
    CONSTRAINT uq_asistencia_inscripcion_fecha UNIQUE (fk_inscripcion, fecha)
);

-- 15. Tabla de Reservas de Espacios
CREATE TABLE reservas (
    id SERIAL PRIMARY KEY,
    fk_espacio INT NOT NULL REFERENCES espacios(id) ON DELETE CASCADE,
    fk_usuario INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE, -- Alumno reservante
    fecha_de_asistencia DATE NOT NULL,
    horario TIME NOT NULL,
    estado VARCHAR(20) DEFAULT 'reservado', -- 'reservado', 'cancelado'
    CONSTRAINT uq_reserva_cancha_fecha_hora UNIQUE (fk_espacio, fecha_de_asistencia, horario)
);
