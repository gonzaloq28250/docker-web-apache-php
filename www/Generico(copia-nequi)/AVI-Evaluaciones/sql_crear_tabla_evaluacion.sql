-- ============================================
-- Tabla para almacenar evaluaciones de transcripciones
-- ============================================
CREATE TABLE IF NOT EXISTS level_transcripciones_evaluacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    F9CallID VARCHAR(255) NOT NULL,
    ANI VARCHAR(255) DEFAULT NULL,
    resultado ENUM('pasa', 'no_pasa') NOT NULL,
    call_result_correcto VARCHAR(255) DEFAULT NULL,
    observacion TEXT,
    fecha_evaluacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cliente VARCHAR(50) NOT NULL DEFAULT 'NEQUI',
    INDEX idx_f9callid (F9CallID),
    INDEX idx_cliente (cliente),
    INDEX idx_fecha_evaluacion (fecha_evaluacion),
    UNIQUE KEY uk_f9callid_cliente (F9CallID, cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
