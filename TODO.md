# Bases adjuntas (ATTACH): las pragma tienen que llevar el esquema
	Sin el esquema, sqlite resuelve el nombre contra la primera base adjunta que
	tenga una tabla (o un índice) que se llame igual, así que se devuelve la
	información de otra tabla, o ninguna. Se veía con `usuarios_cepaim.usuarios`,
	que acababa leyendo `main.usuarios`: `getTableSchema()->foreignKeys` salía
	vacío y no había forma de saber qué clave ajena fallaba.
	- Hechos:
		- Schema::findConstraints: `PRAGMA esquema.foreign_key_list(tabla)`,
		  como ya hacía findColumns. `$table->name` viene sin esquema; está en
		  `$table->schemaName`.
		- Schema::findUniqueIndexes: igual con `index_list` y con el `index_info`
		  de cada índice.
		- Schema::loadTableConstraints: el `INDEX_INFO` de cada índice.
	- Por repasar:
		- Los nombres de las tablas referenciadas que devuelve `foreign_key_list`
		  siguen viniendo sin esquema, aunque están en el mismo que la tabla. Quien
		  los use para consultar tiene que cualificarlos a mano.
		- QueryBuilder: revisar si las consultas que recrean tablas (alterColumn,
		  dropColumn...) tienen el mismo problema con las bases adjuntas.
# Schema::loadTableConstraints
	- Hecho: no llegaba a ejecutar la consulta de `index_list`; construía el sql
	  en $sql y luego usaba $indexes, que no existía.
# Refactor:
	- AlterColumn es el modelo
	- Hechos:
		- AlterColumn
		- Dropcolumn
# Expression
- SELECT strftime('%Y', date_column) AS year FROM your_table;
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
