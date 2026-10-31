-- Roles del sistema. Se puede ejecutar más de una vez.
-- La matriz de permisos (rol_permisos) no se carga aquí: la define la Junta Directiva (HU-07).

INSERT INTO roles (codigo, nombre) VALUES
    ('admin_plataforma', 'Administrador de la plataforma'),
    ('admin_tecnico',    'Administrador técnico'),
    ('presidente',       'Presidente'),
    ('secretario',       'Secretario'),
    ('tesorero',         'Tesorero'),
    ('fiscal',           'Fiscal'),
    ('vocal',            'Vocal'),
    ('cobrador',         'Cobrador'),
    ('guardia',          'Guardia'),
    ('residente',        'Residente')
ON CONFLICT (codigo) DO NOTHING;
