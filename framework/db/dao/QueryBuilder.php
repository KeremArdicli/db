<?php
/**
 * This file contains the Command class.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2012 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db\dao;

use yii\db\Exception;

/**
 * QueryBuilder builds a SQL statement based on the specification given as a [[Query]] object.
 *
 * QueryBuilder is often used behind the scenes by [[Query]] to build a DBMS-dependent SQL statement
 * from a [[Query]] object.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends \yii\base\Object
{
	/**
	 * @var Connection the database connection.
	 */
	public $connection;
	/**
	 * @var string the separator between different fragments of a SQL statement.
	 * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
	 */
	public $separator = " ";
	/**
	 * @var boolean whether to automatically quote table and column names when generating SQL statements.
	 */
	public $autoQuote = true;
	/**
	 * @var array the abstract column types mapped to physical column types.
	 * This is mainly used to support creating/modifying tables using DB-independent data type specifications.
	 * Child classes should override this property to declare supported type mappings.
	 */
	public $typeMap = array();
	/**
	 * @var Query the Query object that is currently processed by the query builder to generate a SQL statement.
	 */
	public $query;

	/**
	 * Constructor.
	 * @param Connection $connection the database connection.
	 */
	public function __construct($connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Generates a SQL statement from a [[Query]] object.
	 * Note that when generating SQL statements for INSERT and UPDATE queries,
	 * the query object's [[Query::params]] property may be appended with new parameters.
	 * @param Query $query the [[Query]] object from which the SQL statement will be generated
	 * @return string the generated SQL statement
	 */
	public function build($query)
	{
		$this->query = $query;
		if ($query->operation !== null) {
			// non-SELECT query
			$params = $query->operation;
			$method = array_shift($params);
			return call_user_func_array(array($this, $method), $params);
		} else {
			// SELECT query
			$clauses = array(
				$this->buildSelect(),
				$this->buildFrom(),
				$this->buildJoin(),
				$this->buildWhere(),
				$this->buildGroupBy(),
				$this->buildHaving(),
				$this->buildUnion(),
				$this->buildOrderBy(),
				$this->buildLimit(),
			);
			return implode($this->separator, array_filter($clauses));
		}
	}

	/**
	 * Creates and executes an INSERT SQL statement.
	 * The method will properly escape the column names, and bind the values to be inserted.
	 * For example,
	 *
	 * ~~~
	 * $params = array();
	 * $sql = $queryBuilder->insert('tbl_user', array(
	 *	 'name' => 'Sam',
	 *	 'age' => 30,
	 * ), $params);
	 * ~~~
	 *
	 * @param string $table the table that new rows will be inserted into.
	 * @param array $columns the column data (name=>value) to be inserted into the table.
	 * @param array $params the parameters to be bound to the query. This method will modify
	 * this parameter by appending new parameters to be bound to the query.
	 * @return integer number of rows affected by the execution.
	 */
	public function insert($table, $columns, &$params = array())
	{
		$names = array();
		$placeholders = array();
		$count = 0;
		foreach ($columns as $name => $value) {
			$names[] = $this->quoteColumnName($name);
			if ($value instanceof Expression) {
				$placeholders[] = $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				$placeholders[] = ':p' . $count;
				$params[':p' . $count] = $value;
				$count++;
			}
		}
		if ($this->query instanceof Query) {
			$this->query->addParams($params);
		}

		return 'INSERT INTO ' . $this->quoteTableName($table)
			. ' (' . implode(', ', $names) . ') VALUES ('
			. implode(', ', $placeholders) . ')';
	}

	/**
	 * Creates and executes an UPDATE SQL statement.
	 * The method will properly escape the column names and bind the values to be updated.
	 * For example,
	 *
	 * ~~~
	 * $params = array();
	 * $sql = $queryBuilder->update('tbl_user', array(
	 *	 'status' => 1,
	 * ), 'age > 30', $params);
	 * ~~~
	 *
	 * @param string $table the table to be updated.
	 * @param array $columns the column data (name=>value) to be updated.
	 * @param mixed $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the parameters to be bound to the query. This method will modify
	 * this parameter by appending new parameters to be bound to the query.
	 * @return integer number of rows affected by the execution.
	 */
	public function update($table, $columns, $condition = '', &$params = array())
	{
		$lines = array();
		$count = 0;
		foreach ($columns as $name => $value) {
			if ($value instanceof Expression) {
				$lines[] = $this->quoteColumnName($name, true) . '=' . $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				$lines[] = $this->quoteColumnName($name, true) . '=:p' . $count;
				$params[':p' . $count] = $value;
				$count++;
			}
		}
		if ($this->query instanceof Query) {
			$this->query->addParams($params);
		}
		$sql = 'UPDATE ' . $this->quoteTableName($table) . ' SET ' . implode(', ', $lines);
		if (($where = $this->buildCondition($condition)) != '') {
			$sql .= ' WHERE ' . $where;
		}

		return $sql;
	}

	/**
	 * Creates and executes a DELETE SQL statement.
	 * For example,
	 *
	 * ~~~
	 * $sql = $queryBuilder->delete('tbl_user', 'status = 0');
	 * ~~~
	 *
	 * @param string $table the table where the data will be deleted from.
	 * @param mixed $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @return integer number of rows affected by the execution.
	 */
	public function delete($table, $condition = '')
	{
		$sql = 'DELETE FROM ' . $this->quoteTableName($table);
		if (($where = $this->buildCondition($condition)) != '') {
			$sql .= ' WHERE ' . $where;
		}
		return $sql;
	}

	/**
	 * Builds a SQL statement for creating a new DB table.
	 *
	 * The columns in the new  table should be specified as name-definition pairs (e.g. 'name'=>'string'),
	 * where name stands for a column name which will be properly quoted by the method, and definition
	 * stands for the column type which can contain an abstract DB type.
	 * The [[getColumnType()]] method will be invoked to convert any abstract type into a physical one.
	 *
	 * If a column is specified with definition only (e.g. 'PRIMARY KEY (name, type)'), it will be directly
	 * inserted into the generated SQL.
	 *
	 * For example,
	 *
	 * ~~~
	 * $sql = $queryBuilder->createTable('tbl_user', array(
	 *	 'id' => 'pk',
	 *	 'name' => 'string',
	 *	 'age' => 'integer',
	 * ));
	 * ~~~
	 *
	 * @param string $table the name of the table to be created. The name will be properly quoted by the method.
	 * @param array $columns the columns (name=>definition) in the new table.
	 * @param string $options additional SQL fragment that will be appended to the generated SQL.
	 * @return string the SQL statement for creating a new DB table.
	 */
	public function createTable($table, $columns, $options = null)
	{
		$cols = array();
		foreach ($columns as $name => $type) {
			if (is_string($name)) {
				$cols[] = "\t" . $this->quoteColumnName($name) . ' ' . $this->getColumnType($type);
			} else {
				$cols[] = "\t" . $type;
			}
		}
		$sql = "CREATE TABLE " . $this->quoteTableName($table) . " (\n" . implode(",\n", $cols) . "\n)";
		return $options === null ? $sql : $sql . ' ' . $options;
	}

	/**
	 * Builds a SQL statement for renaming a DB table.
	 * @param string $oldName the table to be renamed. The name will be properly quoted by the method.
	 * @param string $newName the new table name. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB table.
	 */
	public function renameTable($oldName, $newName)
	{
		return 'RENAME TABLE ' . $this->quoteTableName($oldName) . ' TO ' . $this->quoteTableName($newName);
	}

	/**
	 * Builds a SQL statement for dropping a DB table.
	 * @param string $table the table to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a DB table.
	 */
	public function dropTable($table)
	{
		return "DROP TABLE " . $this->quoteTableName($table);
	}

	/**
	 * Builds a SQL statement for truncating a DB table.
	 * @param string $table the table to be truncated. The name will be properly quoted by the method.
	 * @return string the SQL statement for truncating a DB table.
	 */
	public function truncateTable($table)
	{
		return "TRUNCATE TABLE " . $this->quoteTableName($table);
	}

	/**
	 * Builds a SQL statement for adding a new DB column.
	 * @param string $table the table that the new column will be added to. The table name will be properly quoted by the method.
	 * @param string $column the name of the new column. The name will be properly quoted by the method.
	 * @param string $type the column type. The [[getColumnType()]] method will be invoked to convert abstract column type (if any)
	 * into the physical one. Anything that is not recognized as abstract type will be kept in the generated SQL.
	 * For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become 'varchar(255) not null'.
	 * @return string the SQL statement for adding a new column.
	 */
	public function addColumn($table, $column, $type)
	{
		return 'ALTER TABLE ' . $this->quoteTableName($table)
			. ' ADD ' . $this->quoteColumnName($column) . ' '
			. $this->getColumnType($type);
	}

	/**
	 * Builds a SQL statement for dropping a DB column.
	 * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
	 * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a DB column.
	 */
	public function dropColumn($table, $column)
	{
		return "ALTER TABLE " . $this->quoteTableName($table)
			. " DROP COLUMN " . $this->quoteColumnName($column, true);
	}

	/**
	 * Builds a SQL statement for renaming a column.
	 * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
	 * @param string $oldName the old name of the column. The name will be properly quoted by the method.
	 * @param string $newName the new name of the column. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB column.
	 */
	public function renameColumn($table, $oldName, $newName)
	{
		return "ALTER TABLE " . $this->quoteTableName($table)
			. " RENAME COLUMN " . $this->quoteColumnName($oldName, true)
			. " TO " . $this->quoteColumnName($newName, true);
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
	 */
	public function alterColumn($table, $column, $type)
	{
		return 'ALTER TABLE ' . $this->quoteTableName($table) . ' CHANGE '
			. $this->quoteColumnName($column, true) . ' '
			. $this->quoteColumnName($column, true) . ' '
			. $this->getColumnType($type);
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
	public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
	{
		$sql = 'ALTER TABLE ' . $this->quoteTableName($table)
			. ' ADD CONSTRAINT ' . $this->quoteColumnName($name)
			. ' FOREIGN KEY (' . $this->buildColumns($columns) . ')'
			. ' REFERENCES ' . $this->quoteTableName($refTable)
			. ' (' . $this->buildColumns($refColumns) . ')';
		if ($delete !== null) {
			$sql .= ' ON DELETE ' . $delete;
		}
		if ($update !== null) {
			$sql .= ' ON UPDATE ' . $update;
		}
		return $sql;
	}

	/**
	 * Builds a SQL statement for dropping a foreign key constraint.
	 * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a foreign key constraint.
	 */
	public function dropForeignKey($name, $table)
	{
		return 'ALTER TABLE ' . $this->quoteTableName($table)
			. ' DROP CONSTRAINT ' . $this->quoteColumnName($name);
	}

	/**
	 * Builds a SQL statement for creating a new index.
	 * @param string $name the name of the index. The name will be properly quoted by the method.
	 * @param string $table the table that the new index will be created for. The table name will be properly quoted by the method.
	 * @param string|array $columns the column(s) that should be included in the index. If there are multiple columns,
	 * separate them with commas or use an array to represent them. Each column name will be properly quoted
	 * by the method, unless a parenthesis is found in the name.
	 * @param boolean $unique whether to add UNIQUE constraint on the created index.
	 * @return string the SQL statement for creating a new index.
	 */
	public function createIndex($name, $table, $columns, $unique = false)
	{
		return ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
			. $this->quoteTableName($name) . ' ON '
			. $this->quoteTableName($table)
			. ' (' . $this->buildColumns($columns) . ')';
	}

	/**
	 * Builds a SQL statement for dropping an index.
	 * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping an index.
	 */
	public function dropIndex($name, $table)
	{
		return 'DROP INDEX ' . $this->quoteTableName($name) . ' ON ' . $this->quoteTableName($table);
	}

	/**
	 * Resets the sequence value of a table's primary key.
	 * The sequence will be reset such that the primary key of the next new row inserted
	 * will have the specified value or 1.
	 * @param string $table the table schema whose primary key sequence will be reset
	 * @param mixed $value the value for the primary key of the next new row inserted. If this is not set,
	 * the next new row's primary key will have a value 1.
	 */
	public function resetSequence($table, $value = null)
	{
	}

	/**
	 * Enables or disables integrity check.
	 * @param boolean $check whether to turn on or off the integrity check.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
	 */
	public function checkIntegrity($check = true, $schema = '')
	{
	}

	/**
	 * Converts an abstract column type into a physical column type.
	 * The conversion is done using the type map specified in [[typeMap]].
	 * The following abstract column types are supported (using MySQL as an example to explain the corresponding
	 * physical types):
	 *
	 * - `pk`: an auto-incremental primary key type, will be converted into "int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY"
	 * - `string`: string type, will be converted into "varchar(255)"
	 * - `text`: a long string type, will be converted into "text"
	 * - `smallint`: a small integer type, will be converted into "smallint(6)"
	 * - `integer`: integer type, will be converted into "int(11)"
	 * - `bigint`: a big integer type, will be converted into "bigint(20)"
	 * - `boolean`: boolean type, will be converted into "tinyint(1)"
	 * - `float``: float number type, will be converted into "float"
	 * - `decimal`: decimal number type, will be converted into "decimal"
	 * - `datetime`: datetime type, will be converted into "datetime"
	 * - `timestamp`: timestamp type, will be converted into "timestamp"
	 * - `time`: time type, will be converted into "time"
	 * - `date`: date type, will be converted into "date"
	 * - `money`: money type, will be converted into "decimal(19,4)"
	 * - `binary`: binary data type, will be converted into "blob"
	 *
	 * If the abstract type contains two or more parts separated by spaces (e.g. "string NOT NULL"), then only
	 * the first part will be converted, and the rest of the parts will be appended to the converted result.
	 * For example, 'string NOT NULL' is converted to 'varchar(255) NOT NULL'.
	 *
	 * If a type cannot be found in [[typeMap]], it will be returned without any change.
	 * @param string $type abstract column type
	 * @return string physical column type.
	 */
	public function getColumnType($type)
	{
		if (isset($this->typeMap[$type])) {
			return $this->typeMap[$type];
		} elseif (preg_match('/^(\w+)\s+/', $type, $matches)) {
			if (isset($this->typeMap[$matches[0]])) {
				return preg_replace('/^\w+/', $this->typeMap[$matches[0]], $type);
			}
		}
		return $type;
	}

	/**
	 * Parses the condition specification and generates the corresponding SQL expression.
	 * @param string|array $condition the condition specification. Please refer to [[BaseQuery::where()]]
	 * on how to specify a condition.
	 * @return string the generated SQL expression
	 * @throws \yii\db\Exception if the condition is in bad format
	 */
	public function buildCondition($condition)
	{
		static $builders = array(
			'and' => 'buildAndCondition',
			'or' => 'buildAndCondition',
			'between' => 'buildBetweenCondition',
			'not between' => 'buildBetweenCondition',
			'in' => 'buildInCondition',
			'not in' => 'buildInCondition',
			'like' => 'buildLikeCondition',
			'not like' => 'buildLikeCondition',
			'or like' => 'buildLikeCondition',
			'or not like' => 'buildLikeCondition',
		);

		if (!is_array($condition)) {
			return $condition;
		} elseif ($condition === array()) {
			return '';
		}
		if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
			$operator = $condition[0];
			if (isset($builders[$operator])) {
				$method = $builders[$operator];
				array_shift($condition);
				return $this->$method($operator, $condition);
			} else {
				throw new Exception('Found unknown operator in query: ' . $operator);
			}
		} else { // hash format: 'column1'=>'value1', 'column2'=>'value2', ...
			return $this->buildHashCondition($condition);
		}
	}

	private function buildHashCondition($condition)
	{
		$parts = array();
		foreach ($condition as $column => $value) {
			if (is_array($value)) { // IN condition
				$parts[] = $this->buildInCondition('in', array($column, $value));
			} else {
				if (strpos($column, '(') === false) {
					$column = $this->quoteColumnName($column);
				}
				if ($value === null) {
					$parts[] = "$column IS NULL";
				} elseif (is_string($value)) {
					$parts[] = "$column=" . $this->connection->quoteValue($value);
				} else {
					$parts[] = "$column=$value";
				}
			}
		}
		return '(' . implode(') AND (', $parts) . ')';
	}

	private function buildAndCondition($operator, $operands)
	{
		$parts = array();
		foreach ($operands as $operand) {
			if (is_array($operand)) {
				$operand = $this->buildCondition($operand);
			}
			if ($operand !== '') {
				$parts[] = $operand;
			}
		}
		if ($parts !== array()) {
			return '(' . implode(") $operator (", $parts) . ')';
		} else {
			return '';
		}
	}

	private function buildBetweenCondition($operator, $operands)
	{
		if (!isset($operands[0], $operands[1], $operands[2])) {
			throw new Exception("Operator '$operator' requires three operands.");
		}

		list($column, $value1, $value2) = $operands;

		if (strpos($column, '(') === false) {
			$column = $this->quoteColumnName($column);
		}
		$value1 = is_string($value1) ? $this->connection->quoteValue($value1) : (string)$value1;
		$value2 = is_string($value2) ? $this->connection->quoteValue($value2) : (string)$value2;

		return "$column $operator $value1 AND $value2";
	}

	private function buildInCondition($operator, $operands)
	{
		if (!isset($operands[0], $operands[1])) {
			throw new Exception("Operator '$operator' requires two operands.");
		}

		list($column, $values) = $operands;

		if (!is_array($values)) {
			$values = array($values);
		}

		if ($values === array()) {
			return $operator === 'in' ? '0=1' : '';
		}

		foreach ($values as $i => $value) {
			$values[$i] = is_string($value) ? $this->connection->quoteValue($value) : (string)$value;
		}

		if (strpos($column, '(') === false) {
			$column = $this->quoteColumnName($column);
		}

		return "$column $operator (" . implode(', ', $values) . ')';
	}

	private function buildLikeCondition($operator, $operands)
	{
		if (!isset($operands[0], $operands[1])) {
			throw new Exception("Operator '$operator' requires two operands.");
		}

		list($column, $values) = $operands;

		if (!is_array($values)) {
			$values = array($values);
		}

		if ($values === array()) {
			return $operator === 'like' || $operator === 'or like' ? '0=1' : '';
		}

		if ($operator === 'like' || $operator === 'not like') {
			$andor = ' and ';
		} else {
			$andor = ' or ';
			$operator = $operator === 'or like' ? 'like' : 'not like';
		}

		if (strpos($column, '(') === false) {
			$column = $this->quoteColumnName($column);
		}

		$parts = array();
		foreach ($values as $value) {
			$parts[] = "$column $operator " . $this->connection->quoteValue($value);
		}

		return implode($andor, $parts);
	}

	/**
	 * @return string the SELECT clause built from [[query]].
	 */
	protected function buildSelect()
	{
		$select = $this->query->distinct ? 'SELECT DISTINCT' : 'SELECT';
		if ($this->query->selectOption != '') {
			$select .= ' ' . $this->query->selectOption;
		}

		$columns = $this->query->select;
		if (empty($columns)) {
			return $select . ' *';
		}

		if ($this->autoQuote) {
			$driver = $this->connection->driver;
			if (!is_array($columns)) {
				if (strpos($columns, '(') !== false) {
					return $select . ' ' . $columns;
				} else {
					$columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
				}
			}
			foreach ($columns as $i => $column) {
				if (is_object($column)) {
					$columns[$i] = (string)$column;
				} elseif (strpos($column, '(') === false) {
					if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-\.])$/', $column, $matches)) {
						$columns[$i] = $driver->quoteColumnName($matches[1]) . ' AS ' . $driver->quoteSimpleColumnName($matches[2]);
					} else {
						$columns[$i] = $driver->quoteColumnName($column);
					}
				}
			}
		}

		if (is_array($columns)) {
			$columns = implode(', ', $columns);
		}

		return $select . ' ' . $columns;
	}

	/**
	 * @return string the FROM clause built from [[query]].
	 */
	protected function buildFrom()
	{
		if (empty($this->query->from)) {
			return '';
		}

		$tables = $this->query->from;

		if ($this->autoQuote) {
			$driver = $this->connection->driver;
			if (!is_array($tables)) {
				if (strpos($tables, '(') !== false) {
					return 'FROM ' . $tables;
				} else {
					$tables = preg_split('/\s*,\s*/', trim($tables), -1, PREG_SPLIT_NO_EMPTY);
				}
			}
			foreach ($tables as $i => $table) {
				if (strpos($table, '(') === false) {
					if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)(.*)$/i', $table, $matches)) { // with alias
						$tables[$i] = $driver->quoteTableName($matches[1]) . ' ' . $driver->quoteTableName($matches[2]);
					} else {
						$tables[$i] = $driver->quoteTableName($table);
					}
				}
			}
		}

		if (is_array($tables)) {
			$tables = implode(', ', $tables);
		}

		return 'FROM ' . $tables;
	}

	/**
	 * @return string the JOIN clause built from [[query]].
	 */
	protected function buildJoin()
	{
		$joins = $this->query->join;
		if (empty($joins)) {
			return '';
		}
		if (is_string($joins)) {
			return $joins;
		}

		foreach ($joins as $i => $join) {
			if (is_array($join)) { // join type, table name, on-condition
				if (isset($join[0], $join[1])) {
					$table = $join[1];
					if ($this->autoQuote && strpos($table, '(') === false) {
						$driver = $this->connection->driver;
						if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)(.*)$/', $table, $matches)) { // with alias
							$table = $driver->quoteTableName($matches[1]) . ' ' . $driver->quoteTableName($matches[2]);
						} else {
							$table = $driver->quoteTableName($table);
						}
					}
					$joins[$i] = strtoupper($join[0]) . ' ' . $table;
					if (isset($join[2])) { // join condition
						$joins[$i] .= ' ON ' . $this->buildCondition($join[2]);
					}
				} else {
					throw new Exception('A join clause must be specified as an array of at least two elements.');
				}
			}
		}

		return implode("\n", $joins);
	}

	/**
	 * @return string the WHERE clause built from [[query]].
	 */
	protected function buildWhere()
	{
		$where = $this->buildCondition($this->query->where);
		return empty($where) ? '' : 'WHERE ' . $where;
	}

	/**
	 * @return string the GROUP BY clause built from [[query]].
	 */
	protected function buildGroupBy()
	{
		if (empty($this->query->groupBy)) {
			return '';
		} else {
			return 'GROUP BY ' . $this->buildColumns($this->query->groupBy);
		}
	}

	/**
	 * @return string the HAVING clause built from [[query]].
	 */
	protected function buildHaving()
	{
		$having = $this->buildCondition($this->query->having);
		return empty($having) ? '' : 'HAVING ' . $having;
	}

	/**
	 * @return string the ORDER BY clause built from [[query]].
	 */
	protected function buildOrderBy()
	{
		if (empty($this->query->orderBy)) {
			return '';
		}
		$columns = $this->query->orderBy;
		if ($this->autoQuote) {
			$driver = $this->connection->driver;
			if (!is_array($columns)) {
				if (strpos($columns, '(') !== false) {
					return 'ORDER BY ' . $columns;
				} else {
					$columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
				}
			}
			foreach ($columns as $i => $column) {
				if (is_object($column)) {
					$columns[$i] = (string)$column;
				} elseif (strpos($column, '(') === false) {
					if (preg_match('/^(.*?)\s+(asc|desc)$/i', $column, $matches)) {
						$columns[$i] = $driver->quoteColumnName($matches[1]) . ' ' . $matches[2];
					} else {
						$columns[$i] = $driver->quoteColumnName($column);
					}
				}
			}
		}
		if (is_array($columns)) {
			$columns = implode(', ', $columns);
		}
		return 'ORDER BY ' . $columns;
	}

	/**
	 * @return string the LIMIT and OFFSET clauses built from [[query]].
	 */
	protected function buildLimit()
	{
		$sql = '';
		if ($this->query->limit !== null && $this->query->limit >= 0) {
			$sql = 'LIMIT ' . (int)$this->query->limit;
		}
		if ($this->query->offset > 0) {
			$sql .= ' OFFSET ' . (int)$this->query->offset;
		}
		return ltrim($sql);
	}

	/**
	 * @return string the UNION clause built from [[query]].
	 */
	protected function buildUnion()
	{
		$unions = $this->query->union;
		if (empty($unions)) {
			return '';
		}
		if (!is_array($unions)) {
			$unions = array($unions);
		}
		foreach ($unions as $i => $union) {
			if ($union instanceof Query) {
				$unions[$i] = $union->getSql($this->connection);
			}
		}
		return "UNION (\n" . implode("\n) UNION (\n", $unions) . "\n)";
	}

	/**
	 * Processes columns and properly quote them if necessary.
	 * This method will quote columns if [[autoQuote]] is true.
	 * It will join all columns into a string with comma as separators.
	 * @param string|array $columns the columns to be processed
	 * @return string the processing result
	 */
	protected function buildColumns($columns)
	{
		if ($this->autoQuote) {
			if (!is_array($columns)) {
				if (strpos($columns, '(') !== false) {
					return $columns;
				} else {
					$columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
				}
			}
			foreach ($columns as $i => $column) {
				if (is_object($column)) {
					$columns[$i] = (string)$column;
				} elseif (strpos($column, '(') === false) {
					$columns[$i] = $this->quoteColumnName($column);
				}
			}
		}
		return is_array($columns) ? implode(', ', $columns) : $columns;
	}

	/**
	 * Quotes a table name for use in a query.
	 * This method will perform name quoting only when [[autoQuote]] is true.
	 * @param string $name table name
	 * @param boolean $simple whether the name should be treated as a simple table name without any prefix.
	 * @return string the properly quoted table name
	 */
	protected function quoteTableName($name, $simple = false)
	{
		if ($this->autoQuote) {
			return $this->connection->quoteTableName($name, $simple);
		} else {
			return $name;
		}
	}

	/**
	 * Quotes a column name for use in a query.
	 * This method will perform name quoting only when [[autoQuote]] is true.
	 * @param string $name column name
	 * @param boolean $simple whether the name should be treated as a simple column name without any prefix.
	 * @return string the properly quoted column name
	 */
	protected function quoteColumnName($name, $simple = false)
	{
		if ($this->autoQuote) {
			return $this->connection->quoteColumnName($name, $simple);
		} else {
			return $name;
		}
	}
}
