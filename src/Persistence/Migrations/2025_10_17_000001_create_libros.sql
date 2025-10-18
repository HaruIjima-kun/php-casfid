CREATE TABLE IF NOT EXISTS libros (
                                      id CHAR(36) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    autor VARCHAR(100) NOT NULL,
    isbn VARCHAR(13) NOT NULL,
    anio_publicacion INT NULL,
    descripcion VARCHAR(2000) NULL,
    portada_url VARCHAR(512) NULL,

    titulo_norm VARCHAR(150) AS (LOWER(titulo)) STORED COLLATE utf8mb4_0900_ai_ci,
    autor_norm  VARCHAR(100) AS (LOWER(autor))  STORED COLLATE utf8mb4_0900_ai_ci,

    created_at DATETIME NOT NULL,
    created_by CHAR(36) NOT NULL,
    updated_at DATETIME NULL,
    updated_by CHAR(36) NULL,
    deleted_at DATETIME NULL,
    deleted_by CHAR(36) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_isbn (isbn),
    KEY idx_titulo_norm (titulo_norm),
    KEY idx_autor_norm (autor_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
