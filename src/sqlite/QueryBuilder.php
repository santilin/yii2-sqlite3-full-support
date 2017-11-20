<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db\sqlite;

use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/**
 * QueryBuilder is the query builder for SQLite databases.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends \yii\db\QueryBuilder
{
    /**
     * @var array mapping from abstract column types (keys) to physical column types (values).
     */
    public $typeMap = [
        Schema::TYPE_PK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_UPK => 'integer UNSIGNED PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_BIGPK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_UBIGPK => 'integer UNSIGNED PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_CHAR => 'char(1)',
        Schema::TYPE_STRING => 'varchar(255)',
        Schema::TYPE_TEXT => 'text',
        Schema::TYPE_SMALLINT => 'smallint',
        Schema::TYPE_INTEGER => 'integer',
        Schema::TYPE_BIGINT => 'bigint',
        Schema::TYPE_FLOAT => 'float',
        Schema::TYPE_DOUBLE => 'double',
        Schema::TYPE_DECIMAL => 'decimal(10,0)',
        Schema::TYPE_DATETIME => 'datetime',
        Schema::TYPE_TIMESTAMP => 'timestamp',
        Schema::TYPE_TIME => 'time',
        Schema::TYPE_DATE => 'date',
        Schema::TYPE_BINARY => 'blob',
        Schema::TYPE_BOOLEAN => 'boolean',
        Schema::TYPE_MONEY => 'decimal(19,4)',
    ];

    /**
     * @inheritdoc
     */
    protected $likeEscapeCharacter = '\\';


    /**
     * Generates a batch INSERT SQL statement.
     *
     * For example,
     *
     * ```php
     * $connection->createCommand()->batchInsert('user', ['name', 'age'], [
     *     ['Tom', 30],
     *     ['Jane', 20],
     *     ['Linda', 25],
     * ])->execute();
     * ```
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array $columns the column names
     * @param array|\Generator $rows the rows to be batch inserted into the table
     * @return string the batch INSERT SQL statement
     */
    public function batchInsert($table, $columns, $rows)
    {
        if (empty($rows)) {
            return '';
        }

        // SQLite supports batch insert natively since 3.7.11
        // http://www.sqlite.org/releaselog/3_7_11.html
        $this->db->open(); // ensure pdo is not null
        if (version_compare($this->db->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.7.11', '>=')) {
            return parent::batchInsert($table, $columns, $rows);
        }

        $schema = $this->db->getSchema();
        if (($tableSchema = $schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
        } else {
            $columnSchemas = [];
        }

        $values = [];
        foreach ($rows as $row) {
            $vs = [];
            foreach ($row as $i => $value) {
                if (!is_array($value) && isset($columnSchemas[$columns[$i]])) {
                    $value = $columnSchemas[$columns[$i]]->dbTypecast($value);
                }
                if (is_string($value)) {
                    $value = $schema->quoteValue($value);
                } elseif (is_float($value)) {
                    // ensure type cast always has . as decimal separator in all locales
                    $value = str_replace(',', '.', (string) $value);
                } elseif ($value === false) {
                    $value = 0;
                } elseif ($value === null) {
                    $value = 'NULL';
                }
                $vs[] = $value;
            }
            $values[] = implode(', ', $vs);
        }
        if (empty($values)) {
            return '';
        }

        foreach ($columns as $i => $name) {
            $columns[$i] = $schema->quoteColumnName($name);
        }

        return 'INSERT INTO ' . $schema->quoteTableName($table)
        . ' (' . implode(', ', $columns) . ') SELECT ' . implode(' UNION SELECT ', $values);
    }

    /**
     * Creates a SQL statement for resetting the sequence value of a table's primary key.
     * The sequence will be reset such that the primary key of the next new row inserted
     * will have the specified value or 1.
     * @param string $tableName the name of the table whose primary key sequence will be reset
     * @param mixed $value the value for the primary key of the next new row inserted. If this is not set,
     * the next new row's primary key will have a value 1.
     * @return string the SQL statement for resetting sequence
     * @throws InvalidParamException if the table does not exist or there is no sequence associated with the table.
     */
    public function resetSequence($tableName, $value = null)
    {
        $db = $this->db;
        $table = $db->getTableSchema($tableName);
        if ($table !== null && $table->sequenceName !== null) {
            $tableName = $db->quoteTableName($tableName);
            if ($value === null) {
                $key = $this->db->quoteColumnName(reset($table->primaryKey));
                $value = $this->db->useMaster(function (Connection $db) use ($key, $tableName) {
                    return $db->createCommand("SELECT MAX($key) FROM $tableName")->queryScalar();
                });
            } else {
                $value = (int) $value - 1;
            }

            return "UPDATE sqlite_sequence SET seq='$value' WHERE name='{$table->name}'";
        } elseif ($table === null) {
            throw new InvalidParamException("Table not found: $tableName");
        }

        throw new InvalidParamException("There is not sequence associated with table '$tableName'.'");
    }

    /**
     * Enables or disables integrity check.
     * @param bool $check whether to turn on or off the integrity check.
     * @param string $schema the schema of the tables. Meaningless for SQLite.
     * @param string $table the table name. Meaningless for SQLite.
     * @return string the SQL statement for checking integrity
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function checkIntegrity($check = true, $schema = '', $table = '')
    {
        return 'PRAGMA foreign_keys=' . (int) $check;
    }

    /**
     * Builds a SQL statement for truncating a DB table.
     * @param string $table the table to be truncated. The name will be properly quoted by the method.
     * @return string the SQL statement for truncating a DB table.
     */
    public function truncateTable($table)
    {
        return 'DELETE FROM ' . $this->db->quoteTableName($table);
    }

    /**
     * Builds a SQL statement for dropping an index.
     * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping an index.
     */
    public function dropIndex($name, $table)
    {
        return 'DROP INDEX ' . $this->db->quoteTableName($name);
    }

	/// santilin
    // Get the CREATE TABLE statement used to create this table
    private function getCreateTable($tableName)
    {
        $create_table = $this->db->createCommand("select SQL from SQLite_Master where tbl_name = '$tableName' and type='table'")->queryScalar();
        if ($create_table == NULL ) {
            throw new InvalidParamException("Table not found: $tableName");
		}
		return trim($create_table);
	}

	private function getIndexSqls($tableName, $skipColumn = null) 
	{
		// Get all indexes on this table
        $indexes = $this->db->createCommand("select SQL from SQLite_Master where tbl_name = '$tableName' and type='index'")->queryAll();
        if( $skipColumn == null ) {
			return array_column($indexes, "sql");
		} else {
			$return_indexes = [];
			foreach( $indexes as $key => $index ) {
				$code = (new SqlTokenizer($index["sql"]))->tokenize();
				$pattern = (new SqlTokenizer('any CREATE any INDEX any ON any()'))->tokenize();
				// Extract the list of fields of this index
				if (!$code[0]->matches($pattern, 0, $firstMatchIndex, $lastMatchIndex)) {
					throw new InvalidParamException("Table not found: $tableName");
				}
				$found = false;
				$indexFieldsDef = $code[0][$lastMatchIndex - 1];
				$offset = 0;
				while( $indexFieldsDef->offsetExists($offset) ) {
					$token = $indexFieldsDef[$offset];
					if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER) {
						if( (string)$token == $column || (string)$token == $quoted_column) {
							$found = true;
							break;
						}
					}
					++$offset;
				}
				if (!$found) {
					// If the index contains this column, do not add it 
					$indexes[] = $index["sql"];
				}
			}
		}
		return $indexes;
	}

    /*
     * @return SqlToken the parsed fields definition part of the create table statement for $tableName
     */ 
    private function getFieldDefinitionsTokens($tableName)
    {
		$create_table = $this->getCreateTable($tableName);
		// Parse de CREATE TABLE statement to skip any use of this column, namely field definitions and FOREIGN KEYS
        $code = (new SqlTokenizer($create_table))->tokenize();
        $pattern = (new SqlTokenizer('any CREATE any TABLE any()'))->tokenize();
        if (!$code[0]->matches($pattern, 0, $firstMatchIndex, $lastMatchIndex)) {
			throw new InvalidParamException("Table not found: $tableName");
        }
        // Get the fields definition and foreign keys tokens
        return $code[0][$lastMatchIndex - 1];
	}
	
    /**
     * Builds a SQL statement for dropping a DB column.
     * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
     * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a DB column.
     * @throws NotSupportedException this is not supported by SQLite
     * @autohr santilin <software@noviolento.es>
     */
    public function dropColumn($tableName, $column)
    {
        // Simulate ALTER TABLE ... DROP COLUMN ...
        $return_queries = [];
		/// @todo warn about triggers
		/// @todo get create table additional info
		$fields_definitions_tokens = getFieldDefinitionsTokens($tablename);
        $ddl_fields_def = '';
        $sql_fields_to_insert = [];
        $skipping = false;
        $column_found = false;
        $quoted_column = $this->db->quoteColumnName($column);
        $quoted_tablename = $this->db->quoteTableName($tableName);
        $offset = 0;
        // Traverse the tokens looking for either an identifier (field name) or a foreign key
        while( $fields_definitions_tokens->offsetExists($offset)) {
			$token = $fields_definitions_tokens[$offset++];
			// These searchs could be done whit another SqlTokenizer, but I don't konw how to do them, the documentation for sqltokenizer si really scarse.
			if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER ) {
				$identifier = (string)$token;
				if( $identifier == $column || $identifier == $quoted_column) {
					// found column definition for $column, set skipping on up until the next ,
					$column_found = $skipping = true;
				} else {
					// another column definition, keep and add to list of fields to select back
					$sql_fields_to_insert[] = $identifier;
					$skipping = false;
				}
			} else if( $token->type == \yii\db\SqlToken::TYPE_KEYWORD) {
				$keyword = (string)$token;
				if( $keyword == "FOREIGN" ) {
					// Foreign key found
					$other_offset = $offset;
					while( $fields_definitions_tokens->offsetExists($other_offset) && $fields_definitions_tokens[$other_offset]->type != \yii\db\SqlToken::TYPE_PARENTHESIS) {
						++$other_offset;
					}
					$foreign_field = (string)$fields_definitions_tokens[$other_offset];
					if ($foreign_field == $column || $foreign_field == $quoted_column) {
						// Found foreign key for $column, skip it
						$skipping = true;
						$offset = $other_offset;
					}
				}
			} else {
				/// @todo is there anything else. Look it up in the sqlite docs
				die("Unexpected: $token");
			}
			if( !$skipping ) {
				$ddl_fields_def .= $token . " ";
			}
			// Skip or keep until the next ,
			while( $fields_definitions_tokens->offsetExists($offset) ) {
				$skip_token = $fields_definitions_tokens[$offset];
				if( !$skipping ) {
					$ddl_fields_def .= (string)$skip_token . " ";
				}
				if ($skip_token->type == \yii\db\SqlToken::TYPE_OPERATOR && (string)$skip_token == ',') {
					$ddl_fields_def .= "\n";
					++$offset;
					$skipping = false;
					break;
				}
				++$offset;
			}
		}
		if (!$column_found) {
			throw new InvalidParamException("column '$column' not found in table '$tableName'");
		}
		$return_queries[] = "SAVEPOINT drop_column_$tableName";
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "PRAGMA triggers = NO";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName("temp_$tableName") . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_def, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT " . join(",", $sql_fields_to_insert) . " FROM " . $this->db->quoteTableName("temp_$tableName");
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName("temp_$tableName");
			
		// Indexes. Skip any index referencing $column
		$return_queries = array_merge($return_queries, $this->getIndexSqls($tableName, $column));
		/// @todo add views
		$return_queries[] = "PRAGMA triggers = YES";
		$return_queries[] = "PRAGMA foreign_keys = YES";
		$return_queries[] = "RELEASE drop_column_$tableName";
		return $return_queries;
    }

    /**
     * Builds a SQL statement for renaming a column.
     * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
     * @param string $oldName the old name of the column. The name will be properly quoted by the method.
     * @param string $newName the new name of the column. The name will be properly quoted by the method.
     * @return string the SQL statement for renaming a DB column.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function renameColumn($table, $oldName, $newName)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for adding a foreign key constraint to an existing table.
     * The method will properly quote the table and column names.
     * @param string $name the name of the foreign key constraint.
     * @param string $table the table that the foreign key constraint will be added to.
     * @param string|array $columns the name of the column to that the constraint will be added on.
     * If there are multiple columns, separate them with commas or use an array to represent them.
     * @param string $refTable the table that the foreign key references to.
     * @param string|array $refColumns the name of the column that the foreign key references to.
     * If there are multiple columns, separate them with commas or use an array to represent them.
     * @param string $delete the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
     * @param string $update the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
     * @return string the SQL statement for adding a foreign key constraint to an existing table.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function addForeignKey($name, $tableName, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
		echo "\n\n\n";
		/// @todo warn about triggers
		/// @todo get create table additional info
		$return_queries = [];
        $quoted_tablename = $this->db->quoteTableName($tableName);
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($tableName);
		$ddl_fields_defs = $fields_definitions_tokens->getSql();
		$ddl_fields_defs .= ", CONSTRAINT " . $this->db->quoteColumnName($name) . " FOREIGN KEY (" . join(",", (array)$columns) . ") REFERENCES $refTable(" . join(",", (array)$refColumns) . ")";
		if( $update != null ) {
			$ddl_fields_defs .= " ON UPDATE $update";
		}
		if( $delete != null ) {
			$ddl_fields_defs .= " ON DELETE $delete";
		}
		$return_queries[] = "SAVEPOINT add_foreign_key_to_$tableName";
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "PRAGMA triggers = NO";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName("temp_$tableName") . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_defs, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT * FROM " . $this->db->quoteTableName("temp_$tableName");
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName("temp_$tableName");
		$return_queries = array_merge($return_queries, $this->getIndexSqls($tableName));
		/// @todo add views
		$return_queries[] = "PRAGMA triggers = YES";
		$return_queries[] = "PRAGMA foreign_keys = YES";
		$return_queries[] = "RELEASE add_foreign_key_to_$tableName";
		return $return_queries;
    }

    /**
     * Builds a SQL statement for dropping a foreign key constraint.
     * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a foreign key constraint.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function dropForeignKey($name, $table)
    {
		return "";
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for renaming a DB table.
     *
     * @param string $table the table to be renamed. The name will be properly quoted by the method.
     * @param string $newName the new table name. The name will be properly quoted by the method.
     * @return string the SQL statement for renaming a DB table.
     */
    public function renameTable($table, $newName)
    {
        return 'ALTER TABLE ' . $this->db->quoteTableName($table) . ' RENAME TO ' . $this->db->quoteTableName($newName);
    }

    /**
     * Builds a SQL statement for changing the definition of a column.
     * @param string $table the table whose column is to be changed. The table name will be properly quoted by the method.
     * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $type the new column type. The [[getColumnType()]] method will be invoked to convert abstract
     * column type (if any) into the physical one. Anything that is not recognized as abstract type will be kept
     * in the generated SQL. For example, 'string' will be turned into 'varchar(255)', while 'string not null'
     * will become 'varchar(255) not null'.
     * @return string the SQL statement for changing the definition of a column.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function alterColumn($table, $column, $type)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for adding a primary key constraint to an existing table.
     * @param string $name the name of the primary key constraint.
     * @param string $table the table that the primary key constraint will be added to.
     * @param string|array $columns comma separated string or array of columns that the primary key will consist of.
     * @return string the SQL statement for adding a primary key constraint to an existing table.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function addPrimaryKey($name, $table, $columns)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for removing a primary key constraint to an existing table.
     * @param string $name the name of the primary key constraint to be removed.
     * @param string $table the table that the primary key constraint will be removed from.
     * @return string the SQL statement for removing a primary key constraint from an existing table.
     * @throws NotSupportedException this is not supported by SQLite
     */
    public function dropPrimaryKey($name, $table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function addUnique($name, $table, $columns)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function dropUnique($name, $table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function addCheck($name, $table, $expression)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function dropCheck($name, $table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function addDefaultValue($name, $table, $column, $value)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException this is not supported by SQLite.
     */
    public function dropDefaultValue($name, $table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     * @since 2.0.8
     */
    public function addCommentOnColumn($table, $column, $comment)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     * @since 2.0.8
     */
    public function addCommentOnTable($table, $comment)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     * @since 2.0.8
     */
    public function dropCommentFromColumn($table, $column)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException
     * @since 2.0.8
     */
    public function dropCommentFromTable($table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @inheritdoc
     */
    public function buildLimit($limit, $offset)
    {
        $sql = '';
        if ($this->hasLimit($limit)) {
            $sql = 'LIMIT ' . $limit;
            if ($this->hasOffset($offset)) {
                $sql .= ' OFFSET ' . $offset;
            }
        } elseif ($this->hasOffset($offset)) {
            // limit is not optional in SQLite
            // http://www.sqlite.org/syntaxdiagrams.html#select-stmt
            $sql = "LIMIT 9223372036854775807 OFFSET $offset"; // 2^63-1
        }

        return $sql;
    }

    /**
     * @inheritdoc
     * @throws NotSupportedException if `$columns` is an array
     */
    protected function buildSubqueryInCondition($operator, $columns, $values, &$params)
    {
        if (is_array($columns)) {
            throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
        }

        return parent::buildSubqueryInCondition($operator, $columns, $values, $params);
    }

    /**
     * Builds SQL for IN condition.
     *
     * @param string $operator
     * @param array $columns
     * @param array $values
     * @param array $params
     * @return string SQL
     */
    protected function buildCompositeInCondition($operator, $columns, $values, &$params)
    {
        $quotedColumns = [];
        foreach ($columns as $i => $column) {
            $quotedColumns[$i] = strpos($column, '(') === false ? $this->db->quoteColumnName($column) : $column;
        }
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($columns as $i => $column) {
                if (isset($value[$column])) {
                    $phName = self::PARAM_PREFIX . count($params);
                    $params[$phName] = $value[$column];
                    $vs[] = $quotedColumns[$i] . ($operator === 'IN' ? ' = ' : ' != ') . $phName;
                } else {
                    $vs[] = $quotedColumns[$i] . ($operator === 'IN' ? ' IS' : ' IS NOT') . ' NULL';
                }
            }
            $vss[] = '(' . implode($operator === 'IN' ? ' AND ' : ' OR ', $vs) . ')';
        }

        return '(' . implode($operator === 'IN' ? ' OR ' : ' AND ', $vss) . ')';
    }

    /**
     * @inheritdoc
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        $params = empty($params) ? $query->params : array_merge($params, $query->params);

        $clauses = [
            $this->buildSelect($query->select, $params, $query->distinct, $query->selectOption),
            $this->buildFrom($query->from, $params),
            $this->buildJoin($query->join, $params),
            $this->buildWhere($query->where, $params),
            $this->buildGroupBy($query->groupBy),
            $this->buildHaving($query->having, $params),
        ];

        $sql = implode($this->separator, array_filter($clauses));
        $sql = $this->buildOrderByAndLimit($sql, $query->orderBy, $query->limit, $query->offset);

        if (!empty($query->orderBy)) {
            foreach ($query->orderBy as $expression) {
                if ($expression instanceof Expression) {
                    $params = array_merge($params, $expression->params);
                }
            }
        }
        if (!empty($query->groupBy)) {
            foreach ($query->groupBy as $expression) {
                if ($expression instanceof Expression) {
                    $params = array_merge($params, $expression->params);
                }
            }
        }

        $union = $this->buildUnion($query->union, $params);
        if ($union !== '') {
            $sql = "$sql{$this->separator}$union";
        }

        return [$sql, $params];
    }

    /**
     * @inheritdoc
     */
    public function buildUnion($unions, &$params)
    {
        if (empty($unions)) {
            return '';
        }

        $result = '';

        foreach ($unions as $i => $union) {
            $query = $union['query'];
            if ($query instanceof Query) {
                list($unions[$i]['query'], $params) = $this->build($query, $params);
            }

            $result .= ' UNION ' . ($union['all'] ? 'ALL ' : '') . ' ' . $unions[$i]['query'];
        }

        return trim($result);
    }
}
