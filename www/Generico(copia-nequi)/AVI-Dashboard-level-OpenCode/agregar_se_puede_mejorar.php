<?php
require_once 'config.php';
$pdo = getDBConnection();
try {
    $pdo->exec("ALTER TABLE level_transcripciones_evaluacion
        ADD COLUMN se_puede_mejorar TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Indica si la transcripcion puede mejorarse (0=No, 1=Si)'");
    echo "Columna 'se_puede_mejorar' agregada exitosamente.";
} catch (PDOException $e) {
    if ($e->getCode() == 42000 && strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "La columna ya existe.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
