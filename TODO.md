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
