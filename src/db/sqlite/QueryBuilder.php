<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 * @author santilin (software@noviolento.es)
 */

namespace yii\db\sqlite;
use Yii;
use yii\db\SqlToken;
use yii\db\Exception as DBException;
use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\{Connection,Expression,Query};

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
        Schema::TYPE_TINYINT => 'tinyint',
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
     * {@inheritdoc}
     */
    protected function defaultExpressionBuilders()
    {
        return array_merge(parent::defaultExpressionBuilders(), [
            'yii\db\conditions\LikeCondition' => 'yii\db\sqlite\conditions\LikeConditionBuilder',
            'yii\db\conditions\InCondition' => 'yii\db\sqlite\conditions\InConditionBuilder',
            'yii\db\Expression' => 'santilin\db\sqlite\ExpressionBuilder',
        ]);
    }

    /**
     * {@inheritdoc}
     * @see https://stackoverflow.com/questions/15277373/sqlite-upsert-update-or-insert/15277374#15277374
     */
    public function upsert($table, $insertColumns, $updateColumns, &$params)
    {
        /** @var Constraint[] $constraints */
        list($uniqueNames, $insertNames, $updateNames) = $this->prepareUpsertColumns($table, $insertColumns, $updateColumns, $constraints);
        if (empty($uniqueNames)) {
            return $this->insert($table, $insertColumns, $params);
        }

        list(, $placeholders, $values, $params) = $this->prepareInsertValues($table, $insertColumns, $params);
        $insertSql = 'INSERT OR IGNORE INTO ' . $this->db->quoteTableName($table)
            . (!empty($insertNames) ? ' (' . implode(', ', $insertNames) . ')' : '')
            . (!empty($placeholders) ? ' VALUES (' . implode(', ', $placeholders) . ')' : $values);
        if ($updateColumns === false) {
            return $insertSql;
        }

        $updateCondition = ['or'];
        $quotedTableName = $this->db->quoteTableName($table);
        foreach ($constraints as $constraint) {
            $constraintCondition = ['and'];
            foreach ($constraint->columnNames as $name) {
                $quotedName = $this->db->quoteColumnName($name);
                $constraintCondition[] = "$quotedTableName.$quotedName=(SELECT $quotedName FROM `EXCLUDED`)";
            }
            $updateCondition[] = $constraintCondition;
        }
        if ($updateColumns === true) {
            $updateColumns = [];
            foreach ($updateNames as $name) {
                $quotedName = $this->db->quoteColumnName($name);
                if (strrpos($quotedName, '.') === false) {
                    $quotedName = "(SELECT $quotedName FROM `EXCLUDED`)";
                }
                $updateColumns[$name] = new Expression($quotedName);
            }
        }
        $updateSql = 'WITH "EXCLUDED" (' . implode(', ', $insertNames)
            . ') AS (' . (!empty($placeholders) ? 'VALUES (' . implode(', ', $placeholders) . ')' : ltrim($values, ' ')) . ') '
            . $this->update($table, $updateColumns, $updateCondition, $params);
        return "$updateSql; $insertSql;";
    }

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
    public function batchInsert($table, $columns, $rows, &$params = [])
    {
        if (empty($rows)) {
            return '';
        }

        // SQLite supports batch insert natively since 3.7.11
        // http://www.sqlite.org/releaselog/3_7_11.html
        $this->db->open(); // ensure pdo is not null
        if (version_compare($this->db->getServerVersion(), '3.7.11', '>=')) {
            return parent::batchInsert($table, $columns, $rows, $params);
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
                if (isset($columnSchemas[$columns[$i]])) {
                    $value = $columnSchemas[$columns[$i]]->dbTypecast($value);
                }
                if (is_string($value)) {
                    $value = $schema->quoteValue($value);
                } elseif (is_float($value)) {
                    // ensure type cast always has . as decimal separator in all locales
                    $value = StringHelper::floatToString($value);
                } elseif ($value === false) {
                    $value = 0;
                } elseif ($value === null) {
                    $value = 'NULL';
                } elseif ($value instanceof ExpressionInterface) {
                    $value = $this->buildExpression($value, $params);
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
     * @throws InvalidArgumentException if the table does not exist or there is no sequence associated with the table.
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
            throw new InvalidArgumentException("Table not found: $tableName");
        }

        throw new InvalidArgumentException("There is not sequence associated with table '$tableName'.'");
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
        return 'DROP INDEX ' . $this->db->quoteTableName((string)$name);
    }

	/// santilin
    // Get the CREATE TABLE statement used to create this table
    private function getCreateTable($tableName)
    {
		if( ($pos=strpos($tableName,'.') ) !== false ) {
			$schema = substr($tableName,0, $pos+1);
			$tableName = substr($tableName, $pos+1);
		} else {
			$schema = '';
		}
        $create_table = $this->db->createCommand("select SQL from {$schema}SQLite_Master where tbl_name = '$tableName' and type='table'")->queryScalar();
        if ($create_table == NULL ) {
            throw new InvalidParamException("Table not found: $tableName");
		}
		return trim($create_table);
	}

	// not used so far
	private function getDropIndexes($tableName)
	{
        $indexes = $this->db->createCommand("select name from SQLite_Master where tbl_name = '$tableName' and type='index'")->queryAll();
		foreach( $indexes as $key => $index ) {
			$return_indexes[] = $this->dropIndex($index, $tableName);
		}
		return $return_indexes;
	}

	/**
	 * Search recursively any of the given $search_tokens in the $parent_token
	 * Used to check if an index has a given field:
		ALTER TABLE tblname CREATE INDEX idxname ON (coalesce(fldname,'');
	 */
	private function recursiveTokenHasToken(SqlToken $parent_token, array $search_tokens): bool
	{
		if (!$parent_token->getHasChildren()) {
			foreach ($search_tokens as $token_string => $token_type) {
				if ($token_type == $parent_token->type && $token_string == (string)$parent_token) {
					return true;
				}
			}
		} else for ($index = 0, $count = count($parent_token->children); $index < $count; $index++) {
			$token = $parent_token[$index];
			if ($token->getHasChildren()) {
				return $this->recursiveTokenHasToken($token, $search_tokens);
			}
			foreach ($search_tokens as $token_string => $token_type) {
				if ($token_type == $token->type && $token_string == (string)$token) {
					return true;
				}
			}
        }
        return false;
	}

	private function getIndexSqls($tableName, $skipColumn = null, $newColumn = null)
	{
		// Get all indexes on this table
        $indexes = $this->db->createCommand("select SQL from SQLite_Master where tbl_name = '$tableName' and type='index'")->queryAll();
        if( $skipColumn == null ) {
			return array_column($indexes, "sql");
		}
		$quoted_skip_column = $this->db->quoteColumnName($skipColumn);
		if ($newColumn == null ) {
			// Skip indexes which contain this column
			foreach( $indexes as $key => $index ) {
				$code = (new SqlTokenizer($index["sql"]))->tokenize();
				$pattern = (new SqlTokenizer('any CREATE any INDEX any ON any()'))->tokenize();
				// Extract the list of fields of this index
				if (!$code[0]->matches($pattern, 0, $firstMatchIndex, $lastMatchIndex)) {
					throw new InvalidParamException("Index definition error: $index");
				}
				$found = false;
				$indexFieldsDef = $code[0][$lastMatchIndex - 1];
				$offset = 0;
				while( $indexFieldsDef->offsetExists($offset) ) {
					$token = $indexFieldsDef[$offset];
					if( $this->recursiveTokenHasToken($token, [
						$skipColumn => \yii\db\SqlToken::TYPE_IDENTIFIER,
						$quoted_skip_column => \yii\db\SqlToken::TYPE_IDENTIFIER,
					] )) {
							$found = true;
							unset($indexes[$key]);
							break;
					}
					++$offset;
				}
				if (!$found) {
					// If the index contains this column, do not add it
					$indexes[$key] = $index["sql"];
				}
			}
		} else {
			foreach( $indexes as $key => $index ) {
				$code = (new SqlTokenizer($index["sql"]))->tokenize();
				$pattern = (new SqlTokenizer('any CREATE any INDEX any ON any ()'))->tokenize();
				// Extract the list of fields of this index
				if (!$code[0]->matches($pattern, 0, $firstMatchIndex, $lastMatchIndex)) {
					throw new InvalidParamException("Index definition error: $index");
				}
				$found = false;
				$indexFieldsDef = $code[0][$lastMatchIndex - 1];
				$new_index_def = '';
				for( $i=0; $i<$lastMatchIndex-1; ++$i) {
					$new_index_def .= (string)$code[0][$i] . " ";
				}
				$offset = 0;
				while( $indexFieldsDef->offsetExists($offset) ) {
					$token = $indexFieldsDef[$offset];
					if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER) {
						if( (string)$token == $skipColumn || (string)$token == $quoted_skip_column) {
							$token = $this->db->quoteColumnName($newColumn);
						}
					}
					$new_index_def .= $token;
					++$offset;
				}
				while( $code[0]->offsetExists($lastMatchIndex) ) {
					$new_index_def .= (string)$code[0][$lastMatchIndex++] . " ";
				}
				$indexes[$key] = $this->dropIndex($code[0][2], $tableName) . ";$new_index_def";
			}
		}
		return $indexes;
	}

	public function unquoteTableName($tableName)
	{
        if (($pos=strpos($tableName, '.')) !== FALSE) {
            $db = substr($tableName,0,$pos);
            $tbl = substr($tableName,$pos+1);
            return $this->db->schema->unquoteSimpleTableName($this->db->quoteSql($db))
                . '.' . $this->db->schema->unquoteSimpleTableName($this->db->quoteSql($tbl));
        } else {
            return $this->db->schema->unquoteSimpleTableName($this->db->quoteSql($tableName));
        }
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

	private function foreignKeysState()
	{
		return $this->db->createCommand("PRAGMA foreign_keys")->queryScalar();
	}

	private function setForeignKeysState(bool $state)
	{
		return $this->db->createCommand("PRAGMA foreign_keys = " . ($state?'1':'0') )->execute();
	}

    /**
     * Builds a SQL statement for creating a new index.
     * @param string $name the name of the index. The name will be properly quoted by the method.
     * @param string $table the table that the new index will be created for. The table name will be properly quoted by the method.
     * @param string|array $columns the column(s) that should be included in the index. If there are multiple columns,
     * separate them with commas or use an array to represent them. Each column name will be properly quoted
     * by the method, unless a parenthesis is found in the name.
     * @param bool $unique whether to add UNIQUE constraint on the created index.
     * @return string the SQL statement for creating a new index.
     */
    public function createIndex($name, $table, $columns, $unique = false)
    {
		$sql = ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
            . $this->db->quoteTableName($name) . ' ON '
            . $this->db->quoteTableName($table)
            . ' (' . $this->buildColumns($columns) . ')';
        $sql = preg_replace_callback(
            '/(`.*`) ON (\{\{(%?)([\w\-]+)\}\}\.\{\{((%?)[\w\-]+)\\}\\})|(`.*`) ON (\{\{(%?)([\w\-]+)\.([\w\-]+)\\}\\})/',
            function ($matches) {
                if (!empty($matches[1])) {
					return $matches[4].".".$matches[1]
					 . " ON {{" .$matches[3].$matches[5] . "}}";
                } else if( !empty($matches[7]) ) {
					return $matches[10].".".$matches[7]
					 . " ON {{" .$matches[9].$matches[11] . "}}";
                }
            },
            $sql
        );
		return $sql;
    }

    /**
     * Builds a SQL statement for dropping a DB column.
     * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
     * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a DB column.
     * @author santilin <software@noviolento.es>
     */
    public function dropColumn($tableName, $column)
    {
        // Simulate ALTER TABLE ... DROP COLUMN ...
        $return_queries = [];
		/// @todo warn about triggers
		/// @todo get create table additional info
        $ddl_fields_def = '';
        $sql_fields_to_insert = [];
        $skipping = false;
        $column_found = false;
        $quoted_column = $this->db->quoteColumnName($column);
        $quoted_tablename = $this->db->quoteTableName($tableName);
        $unquoted_tablename = $this->unquoteTableName($tableName);
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($unquoted_tablename);
        $offset = 0;
        // Traverse the tokens looking for either an identifier (field name) or a foreign key
        while( $fields_definitions_tokens->offsetExists($offset)) {
			$token = $fields_definitions_tokens[$offset++];
			// These searchs could be done with another SqlTokenizer, but I don't konw how to do them, the documentation for sqltokenizer si really scarse.
			if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER ) {
				$identifier = (string)$token;
				if( $identifier == $column || $identifier == $quoted_column
					|| $identifier == "\"$column\"" /* strangely this can happen */ ) {
					// found column definition for $column, set skipping on up until the next ,
					$column_found = $skipping = true;
				} else {
					// another column definition, keep and add to list of fields to select back
					$sql_fields_to_insert[] = $identifier;
					$skipping = false;
				}
			} else if( $token->type == \yii\db\SqlToken::TYPE_KEYWORD) {
				$keyword = (string)$token;
				if( $keyword == 'CONSTRAINT' || $keyword == 'FOREIGN') {
					// Constraint key found
					$other_offset = $offset;
					if ($keyword == 'CONSTRAINT' ) {
						$constraint_name = (string)$fields_definitions_tokens[$other_offset];
					} else {
						$constraint_name = $this->db->quoteColumnName(strval($constraint_pos));
					}
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
// 				if( $skip_token->type == \yii\db\SqlToken::TYPE_TOKEN && strtoupper((string)$skip_token) == 'GENERATED' ) {
// 					++$offset;
// 					while( $fields_definitions_tokens->offsetExists($offset) ) {
// 						$skip_token = $fields_definitions_tokens[$offset];
// 						$s = $skip_token->content;
// 						if( $skip_token->type == \yii\db\SqlToken::TYPE_KEYWORD && strtoupper((string)$skip_token) == 'VIRTUAL') {
// 							++$offset;
// 							break;
// 						}
// 						++$offset;
// 					}
// 					if (!$fields_definitions_tokens->offsetExists($offset) ) {
// 						throw new DBException($tableName . '.' . $column . ":Virtual field definition error");
// 					}
// 					$skip_token = $fields_definitions_tokens[$offset];
// 				}
				if( $skip_token->type == \yii\db\SqlToken::TYPE_OPERATOR && (string)$skip_token == ',') {
					if( substr($ddl_fields_def, -1) != "\n" ) $ddl_fields_def .= "\n";
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
		$fks_save = $this->foreignKeysState();
		if( $fks_save == true ) {
			$this->setForeignKeysState(false);
			if( $this->foreignKeysState() == true ) {
				throw new \yii\db\Exception("Unable to disable foreign_keys in " . __FUNCTION__ . ", probably due to being inside a transaction. Set YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 or define the app parma 'disable_foreign_keys=true'");
			}
			$this->setForeignKeysState(true);
		}
		$savepoint = 'drop_column_' . str_replace('.','_',$unquoted_tablename);
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "SAVEPOINT $savepoint";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl') . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_def, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT " . join(",", $sql_fields_to_insert) . " FROM " . $this->db->quoteTableName($unquoted_tablename . '_ddl');
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl');

		// Indexes. Skip any index referencing $column
		$return_queries = array_merge($return_queries,
			$this->getIndexSqls($unquoted_tablename, $column));
		/// @todo add views
		$return_queries[] = "RELEASE $savepoint";
		$return_queries[] = "PRAGMA foreign_keys = $fks_save";
		return implode(";", $return_queries);
	}

	/// copy until the next ,
	private function copyUntilComma($fields_definitions_tokens, &$offset)
	{
		return $this->copyOrSkipUntilComma($fields_definitions_tokens, $offset, false);
	}

	/// skip until the next ,
	private function skipUntilComma($fields_definitions_tokens, &$offset)
	{
		return $this->copyOrSkipUntilComma($fields_definitions_tokens, $offset, true);
	}

	/// Skip or keep until the next ,
	private function copyOrSkipUntilComma($fields_definitions_tokens, &$offset, $skipping = false)
	{
		$ret = '';
		while( $fields_definitions_tokens->offsetExists($offset) ) {
			$skip_token = $fields_definitions_tokens[$offset];
			if ($skip_token->type == \yii\db\SqlToken::TYPE_OPERATOR && (string)$skip_token == ',') {
				$ret .= ",\n";
				++$offset;
				break;
			} else if (!$skipping) {
				$ret .= (string)$skip_token . " ";
			}
			++$offset;
		}
		return $ret;
	}

	/// @todo replace field name in REFERENCES clauses
	/// @todo support rest of constraints: PRIMARY KEY, UNIQUE, ...
	private function renameColumnOnConstraint($fields_definitions_tokens, &$offset, $oldColumn, $newColumn)
	{
		$ret = '';
		$quoted_old_column = $this->db->quoteColumnName($oldColumn);
		$quoted_new_column = $this->db->quoteColumnName($newColumn);
		$pattern_foreign_key = (new SqlTokenizer('FOREIGN any KEY any ()'))->tokenize();
		if ($fields_definitions_tokens->matches($pattern_foreign_key, $offset, $firstMatchIndex, $lastMatchIndex)) {
			while( $offset < $lastMatchIndex - 1 ) {
				$ret .= $fields_definitions_tokens[$offset++] . " ";
			}
			$token = $fields_definitions_tokens[$offset];
			$str_token = $token->getSql();
			$fields = explode(",",$str_token);
			foreach ($fields as $field) {
				if ($field == $oldColumn || $field == $quoted_old_column ) {
					$str_token = $quoted_new_column;
				}
				$ret .= $str_token;
				$offset++;
				if( $fields_definitions_tokens->offsetExists($offset) ) {
					$token = $fields_definitions_tokens[$offset];
					$str_token = $token->content;
				} else {
					throw new \yii\db\Exception("Invalid foreign key definition");
				}
			}
			$ret .= ")";
			$offset++;
		}
		return $ret;
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
     */
    public function addForeignKey($name, $tableName, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
		/// @todo warn about triggers
		/// @todo get create table additional info
		$schema = $refschema = '';
		if( ($pos=strpos($tableName, '.')) !== false ) {
			$schema = $this->unquoteTableName(substr($tableName,0, $pos));
			$tableName = substr($tableName, $pos+1);
		}
		if( ($pos_ref=strpos($refTable, '.')) !== false ) {
			$refschema = substr($refTable, 0, $pos_ref);
			$refTable = substr($refTable, $pos_ref+1);
		}
		if( ($schema != '' || $refschema != '' && $schema != $refschema) ) {
            Yii::info("sqlite3 doesn't support foreign keys across different schemas", __METHOD__);
			return '' ;
		}
		if ($schema != '' ) {
			$tmp_table_name =  "temp_{$schema}_" . $this->unquoteTableName($tableName);
			$schema.='.';
			$unquoted_tablename = $schema . $this->unquoteTableName($tableName);
			$quoted_tablename = $schema . $this->db->quoteTableName($tableName);
		} else {
			$unquoted_tablename = $this->unquoteTableName($tableName);
			$quoted_tablename = $this->db->quoteTableName($tableName);
			$tmp_table_name =  "temp_" . $this->unquoteTableName($tableName);
		}
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($unquoted_tablename);
		$ddl_fields_defs = $fields_definitions_tokens->getSql();
		$ddl_fields_defs .= ",\nCONSTRAINT " . $this->db->quoteColumnName($name) . " FOREIGN KEY (" . join(",", (array)$columns) . ") REFERENCES $refTable(" . join(",", (array)$refColumns) . ")";
		if( $update != null ) {
			$ddl_fields_defs .= " ON UPDATE $update";
		}
		if( $delete != null ) {
			$ddl_fields_defs .= " ON DELETE $delete";
		}
		$return_queries = [];
		$fks_save = $this->foreignKeysState();
		if( $fks_save == true ) {
			$this->setForeignKeysState(false);
			if( $this->foreignKeysState() == true ) {
				throw new \yii\db\Exception("Unable to disable foreign_keys in " . __FUNCTION__ . ", probably due to being inside a transaction. Set YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 or define the app parma 'disable_foreign_keys=true'");
			}
			$this->setForeignKeysState(true);
		}
		// https://sqlite.org/forum/info/143b3dca07642399
		$select_without_hidden_fields = $this->db->createCommand("select group_concat(name, ', ') from pragma_table_info where arg='$unquoted_tablename' order by cid asc")->queryScalar();
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "SAVEPOINT add_foreign_key_to_$tmp_table_name";
		$return_queries[] = "CREATE TEMP TABLE "
			. $this->db->quoteTableName($tmp_table_name)
			. " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_defs, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT $select_without_hidden_fields FROM " . $this->db->quoteTableName($tmp_table_name);
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName($tmp_table_name);
		$return_queries = array_merge($return_queries, $this->getIndexSqls($unquoted_tablename));
		/// @todo add views
		$return_queries[] = "RELEASE add_foreign_key_to_$tmp_table_name";
		$return_queries[] = "PRAGMA foreign_keys = $fks_save";
		return implode(";", $return_queries);
	}

    /**
     * Builds a SQL statement for dropping a foreign key constraint.
     * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
     * Warning: The migrations pass the foreign key name as a number because yii2/db/sqlite/Schema.php doesn't retrieve the foreign key name
     * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
     * @return string the SQL statement for dropping a foreign key constraint.
     */
    public function dropForeignKey($name, $tableName)
    {
        // Simulate ALTER TABLE ... DROP COLUMN ...
        $return_queries = [];
		/// @todo warn about triggers
		/// @todo get create table additional info
        $ddl_fields_def = '';
        $sql_fields_to_insert = [];
        $skipping = false;
        $foreign_found = false;
        $quoted_foreign_name = $this->db->quoteColumnName($name);
        $quoted_tablename = $this->db->quoteTableName($tableName);
        $unquoted_tablename = $this->unquoteTableName($tableName);
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($unquoted_tablename);
        $offset = 0;
        $constraint_pos = 0;
        // Traverse the tokens looking for either an identifier (field name) or a foreign key
        while( $fields_definitions_tokens->offsetExists($offset)) {
			$token = $fields_definitions_tokens[$offset++];
			// These searchs could be done with another SqlTokenizer, but I don't konw how to do them, the documentation for sqltokenizer si really scarse.
			if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER ) {
				$identifier = (string)$token;
				$sql_fields_to_insert[] = $identifier;
			} else if( $token->type == \yii\db\SqlToken::TYPE_KEYWORD) {
				$keyword = (string)$token;
				if( $keyword == 'CONSTRAINT' || $keyword == 'FOREIGN') {
					// Constraint key found
					$other_offset = $offset;
					if ($keyword == 'CONSTRAINT' ) {
						$constraint_name = (string)$fields_definitions_tokens[$other_offset];
					} else {
						$constraint_name = $this->db->quoteColumnName(strval($constraint_pos));
					}
					if ( ($constraint_name == $quoted_foreign_name)
						|| (is_integer($name) && $constraint_pos == $name) ) {
						// Found foreign key $name, skip it
						$foreign_found = true;
						$skipping = true;
						$offset = $other_offset;
					}
					$constraint_pos++;
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
					if( substr($ddl_fields_def,-1) != "\n" ) $ddl_fields_def .= "\n";
					++$offset;
					$skipping = false;
					break;
				}
				++$offset;
			}
		}
		if (!$foreign_found) {
			throw new InvalidParamException("foreign key constraint '$name' not found in table '$tableName'");
		}
		$fks_save = $this->foreignKeysState();
		if( $fks_save == true ) {
			$this->setForeignKeysState(false);
			if( $this->foreignKeysState() == true ) {
				throw new \yii\db\Exception("Unable to disable foreign_keys in " . __FUNCTION__ . ", probably due to being inside a transaction. Set YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 or define the app parma 'disable_foreign_keys=true'");
			}
			$this->setForeignKeysState(true);
		}
        $savepoint = 'drop_foreign_' . str_replace('.','_',$unquoted_tablename);
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "SAVEPOINT $savepoint";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl') . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_def, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT " . join(",", $sql_fields_to_insert) . " FROM " . $this->db->quoteTableName($unquoted_tablename . '_ddl');
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl');

		$return_queries = array_merge($return_queries, $this->getIndexSqls($unquoted_tablename));
		/// @todo add views
		$return_queries[] = "RELEASE $savepoint";
		$return_queries[] = "PRAGMA foreign_keys = $fks_save";
		return implode(";", $return_queries);
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
     * @param string $tableName the table whose column is to be changed. The table name will be properly quoted by the method.
     * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $type the new column type. The [[getColumnType()]] method will be invoked to convert abstract
     * column type (if any) into the physical one. Anything that is not recognized as abstract type will be kept
     * in the generated SQL. For example, 'string' will be turned into 'varchar(255)', while 'string not null'
     * will become 'varchar(255) not null'.
     * @return array the SQL statements for changing the definition of a column.
     * @author santilin <software@noviolento.es>
     */
    public function alterColumn($tableName, $column, $type)
    {
        // Simulate ALTER TABLE ... CHANGE COLUMN ...
        $return_queries = [];
        /// @todo if the change consists only in the default value or the null/not null constraint, do it the easy way
		/// @todo warn about triggers
		/// @todo get create table additional info
        $ddl_fields_def = '';
        $sql_fields_to_insert = [];
        $skipping = false;
        $column_found = false;
        $adding_column_type = false;
        $unquoted_tablename = $this->unquoteTableName($tableName);
        $quoted_tablename = $this->db->quoteTableName($tableName);
        $quoted_column = $this->db->quoteColumnName($column);
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($unquoted_tablename);        $offset = 0;
        // Traverse the tokens looking for either an identifier (field name) or a foreign key
        while( $fields_definitions_tokens->offsetExists($offset)) {
			$token = $fields_definitions_tokens[$offset++];
			// These searchs could be done with another SqlTokenizer, but I don't konw how to do them, the documentation for sqltokenizer si really scarse.
			if( $token->type == \yii\db\SqlToken::TYPE_IDENTIFIER ) {
				$identifier = (string)$token;
				$sql_fields_to_insert[] = $identifier;
				if( $identifier == $column || $identifier == $quoted_column) {
					// found column definition for $column, set skipping on up until the next ,
					$ddl_fields_def .= "$identifier $type";
					$column_found = $skipping = true;
				} else {
					// another column definition, keep and add to list of fields to select back
					$skipping = false;
				}
			} else if( $token->type == \yii\db\SqlToken::TYPE_KEYWORD) {
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
				if ($skip_token->type == \yii\db\SqlToken::TYPE_OPERATOR && (string)$skip_token == ',') {
					$ddl_fields_def .= ",\n";
					++$offset;
					$skipping = false;
					break;
				} else if( !$skipping ) {
					$ddl_fields_def .= (string)$skip_token . " ";
				}
				++$offset;
			}
		}
		if (!$column_found) {
			throw new InvalidParamException("column '$column' not found in table '$tableName'");
		}
		$fks_save = $this->foreignKeysState();
		if( $fks_save == true ) {
			$this->setForeignKeysState(false);
			if( $this->foreignKeysState() == true ) {
				throw new \yii\db\Exception("Unable to disable foreign_keys in " . __FUNCTION__ . ", probably due to being inside a transaction. Set YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 or define the app parma 'disable_foreign_keys=true'");
			}
			$this->setForeignKeysState(true);
		}
        $savepoint = 'alter_column_' . str_replace('.','_',$unquoted_tablename);
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "SAVEPOINT $savepoint";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl') . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_def, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT " . join(",", $sql_fields_to_insert) . " FROM " . $this->db->quoteTableName($unquoted_tablename . '_ddl');
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName($unquoted_tablename . '_ddl');

		// Create indexes for the new table
		$return_queries = array_merge($return_queries, $this->getIndexSqls($unquoted_tablename));
		/// @todo add views
		$return_queries[] = "RELEASE $savepoint";
		$return_queries[] = "PRAGMA foreign_keys = $fks_save";
		return implode(";", $return_queries);
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
		/// @todo warn about triggers
		/// @todo get create table additional info
		$return_queries = [];
		$schema = '';
		if( ($pos=strpos($table, '.')) !== false ) {
			$schema = $this->unquoteTableName(substr($table, 0, $pos));
			$table = substr($table, $pos+1);
			$unquoted_tablename = $schema . '.' . $this->unquoteTableName($table);
			$quoted_tablename = $schema . '.' . $this->db->quoteTableName($table);
			$tmp_table_name =  "temp_{$schema}_" . $this->unquoteTableName($table);
		} else {
			$unquoted_tablename = $this->unquoteTableName($table);
			$quoted_tablename = $this->db->quoteTableName($table);
			$tmp_table_name =  "temp_" . $this->unquoteTableName($table);
		}
		$fields_definitions_tokens = $this->getFieldDefinitionsTokens($unquoted_tablename);
		$ddl_fields_defs = $fields_definitions_tokens->getSql();
		$ddl_fields_defs .= ", CONSTRAINT " . $this->db->quoteColumnName($name) . " PRIMARY KEY (" . join(",", (array)$columns) . ")";
		$fks_save = $this->foreignKeysState();
		if( $fks_save == true ) {
			$this->setForeignKeysState(false);
			if( $this->foreignKeysState() == true ) {
				throw new \yii\db\Exception("Unable to disable foreign_keys in " . __FUNCTION__ . ", probably due to being inside a transaction. Set YII2_SQLITE3_DISABLE_FOREIGN_CHECKS=1 or define the app param 'sqlite3_disable_foreign_keys=true'");
			}
			$this->setForeignKeysState(true);
		}
		$return_queries[] = "PRAGMA foreign_keys = OFF";
		$return_queries[] = "SAVEPOINT add_primary_key_to_$tmp_table_name";
		$return_queries[] = "CREATE TABLE " . $this->db->quoteTableName($tmp_table_name) . " AS SELECT * FROM $quoted_tablename";
		$return_queries[] = "DROP TABLE $quoted_tablename";
		$return_queries[] = "CREATE TABLE $quoted_tablename (" . trim($ddl_fields_defs, " \n\r\t,") . ")";
		$return_queries[] = "INSERT INTO $quoted_tablename SELECT * FROM " . $this->db->quoteTableName($tmp_table_name);
		$return_queries[] = "DROP TABLE " . $this->db->quoteTableName($tmp_table_name);
		$return_queries = array_merge($return_queries, $this->getIndexSqls($unquoted_tablename));
		/// @todo add views
		$return_queries[] = "RELEASE add_primary_key_to_$tmp_table_name";
		$return_queries[] = "PRAGMA foreign_keys = $fks_save";
		return implode(";", $return_queries);
    }

    /**
     * Builds a SQL statement for removing a primary key constraint to an existing table.
     * @param string $name the name of the primary key constraint to be removed.
     * @param string $table the table that the primary key constraint will be removed from.
     * @return string the SQL statement for removing a primary key constraint from an existing table.
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
		return $this->createIndex($name, $table, $columns, true);
    }

    /**
     * @inheritDoc
     */
    public function dropUnique($name, $table)
    {
        return "DROP INDEX $name";
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
     * @since 2.0.8
     */
    public function dropCommentFromColumn($table, $column)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
	}

    /**
     * @inheritdoc
     * @since 2.0.8
     */
    public function dropCommentFromTable($table)
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
	}

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
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

        // Sqlite3 doesn't allow database prefixes in select columns.
        // https://www.sqlite.org/syntax/result-column.html
        // This fixes only SELECT DISTINCT tablename.table.* used in dataprovider counts
        $select = $clauses[0];
        if (preg_match('/SELECT\s*(DISTINCT)?\s*({{.*?}})\.({{.*?}})\.\*/', $select, $matches)) {
            $clauses[0] = str_replace($matches[2] . '.', '', $select);
        }

        $sql = implode($this->separator, array_filter($clauses));
        $sql = $this->buildOrderByAndLimit($sql, $query->orderBy, $query->limit, $query->offset);

        if (!empty($query->orderBy)) {
            foreach ($query->orderBy as $expression) {
                if ($expression instanceof ExpressionInterface)
                {
                    $this->buildExpression($expression, $params);
                }
            }
        }
        if (!empty($query->groupBy)) {
            foreach ($query->groupBy as $expression) {
                if ($expression instanceof ExpressionInterface) {
                    $this->buildExpression($expression, $params);
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
     * {@inheritdoc}
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
