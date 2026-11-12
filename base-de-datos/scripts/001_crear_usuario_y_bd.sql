-- Crea el usuario y la base de datos desde cero.
-- En la máquina donde ya existen patronato y patronato_app NO hace falta ejecutarlo;
-- sirve para instalar el proyecto en otra máquina.
--
-- Ejecutar conectado a la base "postgres" con el usuario "postgres",
-- con auto-commit activado: CREATE DATABASE no puede ir dentro de una transacción.
-- Antes de ejecutar, cambiar la contraseña y copiarla a DB_PASSWORD en presentacion/.env.

CREATE ROLE patronato_app LOGIN PASSWORD 'CAMBIAR_ESTA_CONTRASENA';

CREATE DATABASE patronato
    OWNER patronato_app
    ENCODING 'UTF8'
    TEMPLATE template0;
