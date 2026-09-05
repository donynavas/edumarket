-- Carnet Estudiantil: la institución necesita un logo y un eslogan propios
-- para el encabezado/pie del carnet (antes no existía ningún campo de marca
-- en tbl_institucion; superadmin/instituciones.php solo guarda nombre_ce,
-- subdominio y email). Ambos son opcionales -- un carnet sin logo/eslogan
-- se genera igual, solo con el nombre real de la institución.
ALTER TABLE tbl_institucion
  ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER codigo_infra,
  ADD COLUMN IF NOT EXISTS eslogan VARCHAR(200) NULL AFTER logo_path;
