ALTER TABLE level_transcripciones_evaluacion
ADD COLUMN se_puede_mejorar TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Indica si la transcripción puede mejorarse (0=No, 1=Sí)';
