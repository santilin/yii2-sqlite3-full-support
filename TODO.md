# Refactor:
	- AlterColumn es el modelo
	- Hechos:
		- AlterColumn
		- Dropcolumn
# alterColumn:
- Fallan los índices.
- Si un campo se transforma de nulo a no nulo, falla. Hay que poner el valor por defecto a los nulos en la consulta de creación de la nueva tabla.
# truncateTable: resetSequence
# Estudiar si la tabla está vacía no hace falta desactivar las foreignkeys
# Drop column: no borra la primary key si borramos una primary key
# addColumn: if primary key, add it not primary key and then add primary key



uuid primary key:
CREATE TABLE events (
  id BINARY(16) PRIMARY KEY DEFAULT (UUID_TO_BIN(UUID())),
  event_name VARCHAR(255) NOT NULL,
  event_date DATE NOT NULL
);


CREATE TABLE events (
  id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))),2) || '-' || substr('89ab',abs(random()) % 4 + 1, 1) || substr(lower(hex(randomblob(2))),2) || '-' || lower(hex(randomblob(6)))),
  event_name TEXT NOT NULL,
  event_date DATE NOT NULL
);
