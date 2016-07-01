<?php

/**
 * This file contains rarely used extended database functionality.
 *
 * PortaMx Forum
 * @package PortaMx
 * @author PortaMx & Simple Machines
 * @copyright 2016 PortaMx,  Simple Machines and individual contributors
 * @license http://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1 Beta 4
 */

if (!defined('PMX'))
	die('No direct access...');

/**
 * Add the functions implemented in this file to the $pmxcFunc array.
 */
function db_extra_init()
{
	global $pmxcFunc;

	if (!isset($pmxcFunc['db_backup_table']) || $pmxcFunc['db_backup_table'] != 'pmx_db_backup_table')
		$pmxcFunc += array(
			'db_backup_table' => 'pmx_db_backup_table',
			'db_optimize_table' => 'pmx_db_optimize_table',
			'db_insert_sql' => 'pmx_db_insert_sql',
			'db_table_sql' => 'pmx_db_table_sql',
			'db_list_tables' => 'pmx_db_list_tables',
			'db_get_version' => 'pmx_db_get_version',
			'db_get_engine' => 'pmx_db_get_engine',
		);
}

/**
 * Backup $table to $backup_table.
 * @param string $table The name of the table to backup
 * @param string $backup_table The name of the backup table for this table
 * @return resource -the request handle to the table creation query
 */
function pmx_db_backup_table($table, $backup_table)
{
	global $pmxcFunc, $db_prefix;

	$table = str_replace('{db_prefix}', $db_prefix, $table);

	// Do we need to drop it first?
	$tables = pmx_db_list_tables(false, $backup_table);
	if (!empty($tables))
		$pmxcFunc['db_query']('', '
			DROP TABLE {raw:backup_table}',
			array(
				'backup_table' => $backup_table,
			)
		);

	/**
	 * @todo Should we create backups of sequences as well?
	 */
	$pmxcFunc['db_query']('', '
		CREATE TABLE {raw:backup_table}
		(
			LIKE {raw:table}
			INCLUDING DEFAULTS
		)',
		array(
			'backup_table' => $backup_table,
			'table' => $table,
		)
	);
	$pmxcFunc['db_query']('', '
		INSERT INTO {raw:backup_table}
		SELECT * FROM {raw:table}',
		array(
			'backup_table' => $backup_table,
			'table' => $table,
		)
	);
}

/**
 * This function optimizes a table.
 * @param string $table The table to be optimized
 * @return int How much space was gained
 */
function pmx_db_optimize_table($table)
{
	global $pmxcFunc, $db_prefix;

	$table = str_replace('{db_prefix}', $db_prefix, $table);
	
	$pg_tables = array('pg_catalog','information_schema');
	
	$request = $pmxcFunc['db_query']('', '
		SELECT pg_relation_size(C.oid) AS "size"
		FROM pg_class C
		LEFT JOIN pg_namespace N ON (N.oid = C.relnamespace)
		WHERE nspname NOT IN ({array_string:pg_tables})
		and relname = {string:table}',
		array(
			'table' => $table,
			'pg_tables' => $pg_tables,
		)
	);
	
	$row = $pmxcFunc['db_fetch_assoc']($request);
	$pmxcFunc['db_free_result']($request);
	
	$old_size = $row['size'];

	//pg below 9.0.0 is very slow on full vacuum
	if (substr(pmx_db_get_version(),1) == 8)
	{
		$request = $pmxcFunc['db_query']('', '
			CLUSTER {raw:table} ON {raw:table}_pkey',
			array(
				'table' => $table,
			)
		);
		$request = $pmxcFunc['db_query']('', '
			VALTER TABLE {raw:table} SET WITHOUT CLUSTER',
			array(
				'table' => $table,
			)
		);
		$request = $pmxcFunc['db_query']('', '
			VACUUM ANALYZE {raw:table}',
			array(
				'table' => $table,
			)
		);
	} 
	else
		$request = $pmxcFunc['db_query']('', '
				VACUUM FULL ANALYZE {raw:table}',
				array(
					'table' => $table,
				)
			);
			
	if (!$request)
		return -1;
	
	$request = $pmxcFunc['db_query']('', '
		SELECT pg_relation_size(C.oid) AS "size"
		FROM pg_class C
		LEFT JOIN pg_namespace N ON (N.oid = C.relnamespace)
		WHERE nspname NOT IN ({array_string:pg_tables})
		and relname = {string:table}',
		array(
			'table' => $table,
			'pg_tables' => $pg_tables,
		)
	);
	

	$row = $pmxcFunc['db_fetch_assoc']($request);
	$pmxcFunc['db_free_result']($request);

	if (isset($row['size']))
			return ($old_size - $row['size']) / 1024;
	else
		return 0;
}

/**
 * This function lists all tables in the database.
 * The listing could be filtered according to $filter.
 *
 * @param string|boolean $db string The database name or false to use the current DB
 * @param string|boolean $filter String to filter by or false to list all tables
 * @return array An array of table names
 */
function pmx_db_list_tables($db = false, $filter = false)
{
	global $pmxcFunc;

	$request = $pmxcFunc['db_query']('', '
		SELECT tablename
		FROM pg_tables
		WHERE schemaname = {string:schema_public}' . ($filter == false ? '' : '
			AND tablename LIKE {string:filter}') . '
		ORDER BY tablename',
		array(
			'schema_public' => 'public',
			'filter' => $filter,
		)
	);

	$tables = array();
	while ($row = $pmxcFunc['db_fetch_row']($request))
		$tables[] = $row[0];
	$pmxcFunc['db_free_result']($request);

	return $tables;
}

/**
 * Gets all the necessary INSERTs for the table named table_name.
 * It goes in 250 row segments.
 *
 * @param string $tableName The table to create the inserts for.
 * @param boolean $new_table Whether or not this a new table (resets $start and $limit)
 * @return string The query to insert the data back in, or an empty string if the table was empty.
 */
function pmx_db_insert_sql($tableName, $new_table = false)
{
	global $pmxcFunc, $db_prefix;
	static $start = 0, $num_rows, $fields, $limit;

	if ($new_table)
	{
		$limit = strstr($tableName, 'log_') !== false ? 500 : 250;
		$start = 0;
	}

	$data = '';
	$tableName = str_replace('{db_prefix}', $db_prefix, $tableName);

	// This will be handy...
	$crlf = "\r\n";

	$result = $pmxcFunc['db_query']('', '
		SELECT *
		FROM ' . $tableName . '
		LIMIT ' . $start . ', ' . $limit,
		array(
			'security_override' => true,
		)
	);

	// The number of rows, just for record keeping and breaking INSERTs up.
	$num_rows = $pmxcFunc['db_num_rows']($result);

	if ($num_rows == 0)
		return '';

	if ($new_table)
	{
		$fields = array_keys($pmxcFunc['db_fetch_assoc']($result));
		$pmxcFunc['db_data_seek']($result, 0);
	}

	// Start it off with the basic INSERT INTO.
	$data = '';
	$insert_msg = $crlf . 'INSERT INTO ' . $tableName . $crlf . "\t" . '(' . implode(', ', $fields) . ')' . $crlf . 'VALUES ' . $crlf . "\t";

	// Loop through each row.
	while ($row = $pmxcFunc['db_fetch_assoc']($result))
	{
		// Get the fields in this row...
		$field_list = array();

		foreach ($row as $key => $item)
		{
			// Try to figure out the type of each field. (NULL, number, or 'string'.)
			if (!isset($item))
				$field_list[] = 'NULL';
			elseif (is_numeric($item) && (int) $item == $item)
				$field_list[] = $item;
			else
				$field_list[] = '\'' . $pmxcFunc['db_escape_string']($item) . '\'';
		}

		// 'Insert' the data.
		$data .= $insert_msg . '(' . implode(', ', $field_list) . ');' . $crlf;
	}
	$pmxcFunc['db_free_result']($result);

	$data .= $crlf;

	$start += $limit;

	return $data;
}

/**
 * Dumps the schema (CREATE) for a table.
 * @todo why is this needed for?
 * @param string $tableName The name of the table
 * @return string The "CREATE TABLE" SQL string for this table
 */
function pmx_db_table_sql($tableName)
{
	global $pmxcFunc, $db_prefix;

	$tableName = str_replace('{db_prefix}', $db_prefix, $tableName);

	// This will be needed...
	$crlf = "\r\n";

	// Drop it if it exists.
	$schema_create = 'DROP TABLE IF EXISTS ' . $tableName . ';' . $crlf . $crlf;

	// Start the create table...
	$schema_create .= 'CREATE TABLE ' . $tableName . ' (' . $crlf;
	$index_create = '';
	$seq_create = '';

	// Find all the fields.
	$result = $pmxcFunc['db_query']('', '
		SELECT column_name, column_default, is_nullable, data_type, character_maximum_length
		FROM information_schema.columns
		WHERE table_name = {string:table}
		ORDER BY ordinal_position',
		array(
			'table' => $tableName,
		)
	);
	while ($row = $pmxcFunc['db_fetch_assoc']($result))
	{
		if ($row['data_type'] == 'character varying')
			$row['data_type'] = 'varchar';
		elseif ($row['data_type'] == 'character')
			$row['data_type'] = 'char';
		if ($row['character_maximum_length'])
			$row['data_type'] .= '(' . $row['character_maximum_length'] . ')';

		// Make the CREATE for this column.
		$schema_create .= ' "' . $row['column_name'] . '" ' . $row['data_type'] . ($row['is_nullable'] != 'YES' ? ' NOT NULL' : '');

		// Add a default...?
		if (trim($row['column_default']) != '')
		{
			$schema_create .= ' default ' . $row['column_default'] . '';

			// Auto increment?
			if (preg_match('~nextval\(\'(.+?)\'(.+?)*\)~i', $row['column_default'], $matches) != 0)
			{
				// Get to find the next variable first!
				$count_req = $pmxcFunc['db_query']('', '
					SELECT MAX("{raw:column}")
					FROM {raw:table}',
					array(
						'column' => $row['column_name'],
						'table' => $tableName,
					)
				);
				list ($max_ind) = $pmxcFunc['db_fetch_row']($count_req);
				$pmxcFunc['db_free_result']($count_req);
				// Get the right bloody start!
				$seq_create .= 'CREATE SEQUENCE ' . $matches[1] . ' START WITH ' . ($max_ind + 1) . ';' . $crlf . $crlf;
			}
		}

		$schema_create .= ',' . $crlf;
	}
	$pmxcFunc['db_free_result']($result);

	// Take off the last comma.
	$schema_create = substr($schema_create, 0, -strlen($crlf) - 1);

	$result = $pmxcFunc['db_query']('', '
		SELECT CASE WHEN i.indisprimary THEN 1 ELSE 0 END AS is_primary, pg_get_indexdef(i.indexrelid) AS inddef
		FROM pg_class AS c
			INNER JOIN pg_index AS i ON (i.indrelid = c.oid)
			INNER JOIN pg_class AS c2 ON (c2.oid = i.indexrelid)
		WHERE c.relname = {string:table}',
		array(
			'table' => $tableName,
		)
	);
	$indexes = array();
	while ($row = $pmxcFunc['db_fetch_assoc']($result))
	{
		if ($row['is_primary'])
		{
			if (preg_match('~\(([^\)]+?)\)~i', $row['inddef'], $matches) == 0)
				continue;

			$index_create .= $crlf . 'ALTER TABLE ' . $tableName . ' ADD PRIMARY KEY ("' . $matches[1] . '");';
		}
		else
			$index_create .= $crlf . $row['inddef'] . ';';
	}
	$pmxcFunc['db_free_result']($result);

	// Finish it off!
	$schema_create .= $crlf . ');';

	return $seq_create . $schema_create . $index_create;
}

/**
 *  Get the version number.
 *  @return string The version
 */
function pmx_db_get_version()
{
	static $ver;

	if(!empty($ver))
		return $ver;

	global $pmxcFunc;

	$request = $pmxcFunc['db_query']('', '
		SHOW server_version',
		array(
		)
	);
	list ($ver) = $pmxcFunc['db_fetch_row']($request);
	$pmxcFunc['db_free_result']($request);

	return $ver;
}

/**
 * Return PostgreSQL
 *
 * @return string The database engine we are using
*/
function pmx_db_get_engine()
{
	return 'PostgreSQL';
}

?>