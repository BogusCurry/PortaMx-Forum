<?php

/**
 * This file contains database functionality specifically designed for packages (mods) to utilize.
 *
 * PortaMx Forum
 * @package PortaMx
 * @author PortaMx & Simple Machines
 * @copyright 2016 PortaMx,  Simple Machines and individual contributors
 * @license http://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1 Beta 5
 */

if (!defined('PMX'))
	die('No direct access...');

/**
 * Add the file functions to the $pmxcFunc array.
 */
function db_packages_init()
{
	global $pmxcFunc, $reservedTables, $db_package_log, $db_prefix;

	if (!isset($pmxcFunc['db_create_table']) || $pmxcFunc['db_create_table'] != 'pmx_db_create_table')
	{
		$pmxcFunc += array(
			'db_add_column' => 'pmx_db_add_column',
			'db_add_index' => 'pmx_db_add_index',
			'db_calculate_type' => 'pmx_db_calculate_type',
			'db_change_column' => 'pmx_db_change_column',
			'db_create_table' => 'pmx_db_create_table',
			'db_drop_table' => 'pmx_db_drop_table',
			'db_table_structure' => 'pmx_db_table_structure',
			'db_list_columns' => 'pmx_db_list_columns',
			'db_list_indexes' => 'pmx_db_list_indexes',
			'db_remove_column' => 'pmx_db_remove_column',
			'db_remove_index' => 'pmx_db_remove_index',
		);
		$db_package_log = array();
	}

	// We setup an array of PMX tables we can't do auto-remove on - in case a mod writer cocks it up!
	$reservedTables = array('admin_info_files', 'approval_queue', 'attachments', 'ban_groups', 'ban_items',
		'board_permissions', 'boards', 'calendar', 'calendar_holidays', 'categories',
		'custom_fields', 'group_moderators', 'log_actions', 'log_activity', 'log_banned', 'log_boards',
		'log_digest', 'log_errors', 'log_floodcontrol', 'log_group_requests', 'log_mark_read',
		'log_notify', 'log_online', 'log_packages', 'log_polls', 'log_reported', 'log_reported_comments',
		'log_scheduled_tasks', 'log_search_messages', 'log_search_results', 'log_search_subjects',
		'log_search_topics', 'log_topics', 'mail_queue', 'membergroups', 'members', 'message_icons',
		'messages', 'moderators', 'package_servers', 'permission_profiles', 'permissions', 'personal_messages',
		'pm_recipients', 'poll_choices', 'polls', 'scheduled_tasks', 'sessions', 'settings', 'smileys',
		'themes', 'topics');
	foreach ($reservedTables as $k => $table_name)
		$reservedTables[$k] = strtolower($db_prefix . $table_name);

	// We in turn may need the extra stuff.
	db_extend('extra');
}

/**
 * This function can be used to create a table without worrying about schema
 *  compatibilities across supported database systems.
 *  - If the table exists will, by default, do nothing.
 *  - Builds table with columns as passed to it - at least one column must be sent.
 *  The columns array should have one sub-array for each column - these sub arrays contain:
 *  	'name' = Column name
 *  	'type' = Type of column - values from (smallint, mediumint, int, text, varchar, char, tinytext, mediumtext, largetext)
 *  	'size' => Size of column (If applicable) - for example 255 for a large varchar, 10 for an int etc.
 *  		If not set PMX will pick a size.
 *  	- 'default' = Default value - do not set if no default required.
 *  	- 'null' => Can it be null (true or false) - if not set default will be false.
 *  	- 'auto' => Set to true to make it an auto incrementing column. Set to a numerical value to set from what
 *  		 it should begin counting.
 *  - Adds indexes as specified within indexes parameter. Each index should be a member of $indexes. Values are:
 *  	- 'name' => Index name (If left empty SMF will generate).
 *  	- 'type' => Type of index. Choose from 'primary', 'unique' or 'index'. If not set will default to 'index'.
 *  	- 'columns' => Array containing columns that form part of key - in the order the index is to be created.
 *  - parameters: (None yet)
 *  - if_exists values:
 *  	- 'ignore' will do nothing if the table exists. (And will return true)
 *  	- 'overwrite' will drop any existing table of the same name.
 *  	- 'error' will return false if the table already exists.
 *
 * @param string $table_name The name of the table to create
 * @param array $columns An array of column info in the specified format
 * @param array $indexes An array of index info in the specified format
 * @param array $parameters Currently not used
 * @param string $if_exists What to do if the table exists.
 * @param string $error
 */
function pmx_db_create_table($table_name, $columns, $indexes = array(), $parameters = array(), $if_exists = 'ignore', $error = 'fatal')
{
	global $reservedTables, $pmxcFunc, $db_package_log, $db_prefix;

	// Strip out the table name, we might not need it in some cases
	$real_prefix = preg_match('~^("?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1 ? $match[3] : $db_prefix;

	// With or without the database name, the fullname looks like this.
	$full_table_name = str_replace('{db_prefix}', $real_prefix, $table_name);
	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// First - no way do we touch PMX tables.
	if (in_array(strtolower($table_name), $reservedTables))
		return false;

	// Log that we'll want to remove this on uninstall.
	$db_package_log[] = array('remove_table', $table_name);

	// This... my friends... is a function in a half - let's start by checking if the table exists!
	$tables = $pmxcFunc['db_list_tables']();
	if (in_array($full_table_name, $tables))
	{
		// This is a sad day... drop the table? If not, return false (error) by default.
		if ($if_exists == 'overwrite')
			$pmxcFunc['db_drop_table']($table_name);
		else
			return $if_exists == 'ignore';
	}

	// If we've got this far - good news - no table exists. We can build our own!
	$pmxcFunc['db_transaction']('begin');
	$table_query = 'CREATE TABLE ' . $table_name . "\n" . '(';
	foreach ($columns as $column)
	{
		// If we have an auto increment do it!
		if (!empty($column['auto']))
		{
			$pmxcFunc['db_query']('', '
				CREATE SEQUENCE ' . $table_name . '_seq',
				array(
					'security_override' => true,
				)
			);
			$default = 'default nextval(\'' . $table_name . '_seq\')';
		}
		elseif (isset($column['default']) && $column['default'] !== null)
			$default = 'default \'' . $pmxcFunc['db_escape_string']($column['default']) . '\'';
		else
			$default = '';

		// Sort out the size...
		$column['size'] = isset($column['size']) && is_numeric($column['size']) ? $column['size'] : null;
		list ($type, $size) = $pmxcFunc['db_calculate_type']($column['type'], $column['size']);
		if ($size !== null)
			$type = $type . '(' . $size . ')';

		// Now just put it together!
		$table_query .= "\n\t\"" . $column['name'] . '" ' . $type . ' ' . (!empty($column['null']) ? '' : 'NOT NULL') . ' ' . $default . ',';
	}

	// Loop through the indexes a sec...
	$index_queries = array();
	foreach ($indexes as $index)
	{
		$columns = implode(',', $index['columns']);

		// Primary goes in the table...
		if (isset($index['type']) && $index['type'] == 'primary')
			$table_query .= "\n\t" . 'PRIMARY KEY (' . implode(',', $index['columns']) . '),';
		else
		{
			if (empty($index['name']))
				$index['name'] = implode('_', $index['columns']);
			$index_queries[] = 'CREATE ' . (isset($index['type']) && $index['type'] == 'unique' ? 'UNIQUE' : '') . ' INDEX ' . $table_name . '_' . $index['name'] . ' ON ' . $table_name . ' (' . $columns . ')';
		}
	}

	// No trailing commas!
	if (substr($table_query, -1) == ',')
		$table_query = substr($table_query, 0, -1);

	$table_query .= ')';

	// Create the table!
	$pmxcFunc['db_query']('', $table_query,
		array(
			'security_override' => true,
		)
	);
	// And the indexes...
	foreach ($index_queries as $query)
		$pmxcFunc['db_query']('', $query,
		array(
			'security_override' => true,
		)
	);

	// Go, go power rangers!
	$pmxcFunc['db_transaction']('commit');

	return true;
}

/**
 * Drop a table and its associated sequences.
 *
 * @param string $table_name The name of the table to drop
 * @param array $parameters Not used at the moment
 * @param string $error
 * @return boolean Whether or not the operation was successful
 */
function pmx_db_drop_table($table_name, $parameters = array(), $error = 'fatal')
{
	global $reservedTables, $pmxcFunc, $db_prefix;

	// After stripping away the database name, this is what's left.
	$real_prefix = preg_match('~^("?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1 ? $match[3] : $db_prefix;

	// Get some aliases.
	$full_table_name = str_replace('{db_prefix}', $real_prefix, $table_name);
	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// God no - dropping one of these = bad.
	if (in_array(strtolower($table_name), $reservedTables))
		return false;

	// Does it exist?
	if (in_array($full_table_name, $pmxcFunc['db_list_tables']()))
	{
		// We can then drop the table.
		$pmxcFunc['db_transaction']('begin');

		// the table
		$table_query = 'DROP TABLE ' . $table_name;

		// and the assosciated sequence, if any
		$sequence_query = 'DROP SEQUENCE IF EXISTS ' . $table_name . '_seq';

		// drop them
		$pmxcFunc['db_query']('',
			$table_query,
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_query']('',
			$sequence_query,
			array(
				'security_override' => true,
			)
		);

		$pmxcFunc['db_transaction']('commit');

		return true;
	}

	// Otherwise do 'nout.
	return false;
}

/**
 * This function adds a column.
 *
 * @param string $table_name The name of the table to add the column to
 * @param array $column_info An array of column info (see {@link pmx_db_create_table()})
 * @param array $parameters Not used?
 * @param string $if_exists What to do if the column exists. If 'update', column is updated.
 * @param string $error
 * @return boolean Whether or not the operation was successful
 */
function pmx_db_add_column($table_name, $column_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
{
	global $pmxcFunc, $db_package_log, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// Log that we will want to uninstall this!
	$db_package_log[] = array('remove_column', $table_name, $column_info['name']);

	// Does it exist - if so don't add it again!
	$columns = $pmxcFunc['db_list_columns']($table_name, false);
	foreach ($columns as $column)
		if ($column == $column_info['name'])
		{
			// If we're going to overwrite then use change column.
			if ($if_exists == 'update')
				return $pmxcFunc['db_change_column']($table_name, $column_info['name'], $column_info);
			else
				return false;
		}

	// Get the specifics...
	$column_info['size'] = isset($column_info['size']) && is_numeric($column_info['size']) ? $column_info['size'] : null;
	list ($type, $size) = $pmxcFunc['db_calculate_type']($column_info['type'], $column_info['size']);
	if ($size !== null)
		$type = $type . '(' . $size . ')';

	// Now add the thing!
	$query = '
		ALTER TABLE ' . $table_name . '
		ADD COLUMN ' . $column_info['name'] . ' ' . $type;
	$pmxcFunc['db_query']('', $query,
		array(
			'security_override' => true,
		)
	);

	// If there's more attributes they need to be done via a change on PostgreSQL.
	unset($column_info['type'], $column_info['size']);

	if (count($column_info) != 1)
		return $pmxcFunc['db_change_column']($table_name, $column_info['name'], $column_info);
	else
		return true;
}

/**
 * Removes a column.
 *
 * @param string $table_name The name of the table to drop the column from
 * @param string $column_name The name of the column to drop
 * @param array $parameters Not used?
 * @param string $error
 * @return boolean Whether or not the operation was successful
 */
function pmx_db_remove_column($table_name, $column_name, $parameters = array(), $error = 'fatal')
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// Does it exist?
	$columns = $pmxcFunc['db_list_columns']($table_name, true);
	foreach ($columns as $column)
		if ($column['name'] == $column_name)
		{
			// If there is an auto we need remove it!
			if ($column['auto'])
				$pmxcFunc['db_query']('',
					'DROP SEQUENCE ' . $table_name . '_seq',
					array(
						'security_override' => true,
					)
				);

			$pmxcFunc['db_query']('', '
				ALTER TABLE ' . $table_name . '
				DROP COLUMN ' . $column_name,
				array(
					'security_override' => true,
				)
			);

			return true;
		}

	// If here we didn't have to work - joy!
	return false;
}

/**
 * Change a column.
 *
 * @param string $table_name The name of the table this column is in
 * @param $old_column The name of the column we want to change
 * @param $column_info An array of info about the "new" column definition (see {@link pmx_db_create_table()})
 * @param array $parameters Not used?
 * @param string $error
 */
function pmx_db_change_column($table_name, $old_column, $column_info, $parameters = array(), $error = 'fatal')
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// Check it does exist!
	$columns = $pmxcFunc['db_list_columns']($table_name, true);
	$old_info = null;
	foreach ($columns as $column)
		if ($column['name'] == $old_column)
			$old_info = $column;

	// Nothing?
	if ($old_info == null)
		return false;

	// Now we check each bit individually and ALTER as required.
	if (isset($column_info['name']) && $column_info['name'] != $old_column)
	{
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			RENAME COLUMN ' . $old_column . ' TO ' . $column_info['name'],
			array(
				'security_override' => true,
			)
		);
	}
	// Different default?
	if (isset($column_info['default']) && $column_info['default'] != $old_info['default'])
	{
		$action = $column_info['default'] !== null ? 'SET DEFAULT \'' . $pmxcFunc['db_escape_string']($column_info['default']) . '\'' : 'DROP DEFAULT';
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			ALTER COLUMN ' . $column_info['name'] . ' ' . $action,
			array(
				'security_override' => true,
			)
		);
	}
	// Is it null - or otherwise?
	if (isset($column_info['null']) && $column_info['null'] != $old_info['null'])
	{
		$action = $column_info['null'] ? 'DROP' : 'SET';
		$pmxcFunc['db_transaction']('begin');
		if (!$column_info['null'])
		{
			// We have to set it to something if we are making it NOT NULL. And we must comply with the current column format.
			$setTo = isset($column_info['default']) ? $column_info['default'] : (strpos($old_info['type'], 'int') !== false ? 0 : '');
			$pmxcFunc['db_query']('', '
				UPDATE ' . $table_name . '
				SET ' . $column_info['name'] . ' = \'' . $setTo . '\'
				WHERE ' . $column_info['name'] . ' IS NULL',
				array(
					'security_override' => true,
				)
			);
		}
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			ALTER COLUMN ' . $column_info['name'] . ' ' . $action . ' NOT NULL',
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_transaction']('commit');
	}
	// What about a change in type?
	if (isset($column_info['type']) && ($column_info['type'] != $old_info['type'] || (isset($column_info['size']) && $column_info['size'] != $old_info['size'])))
	{
		$column_info['size'] = isset($column_info['size']) && is_numeric($column_info['size']) ? $column_info['size'] : null;
		list ($type, $size) = $pmxcFunc['db_calculate_type']($column_info['type'], $column_info['size']);
		if ($size !== null)
			$type = $type . '(' . $size . ')';

		// The alter is a pain.
		$pmxcFunc['db_transaction']('begin');
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			ADD COLUMN ' . $column_info['name'] . '_tempxx ' . $type,
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_query']('', '
			UPDATE ' . $table_name . '
			SET ' . $column_info['name'] . '_tempxx = CAST(' . $column_info['name'] . ' AS ' . $type . ')',
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			DROP COLUMN ' . $column_info['name'],
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			RENAME COLUMN ' . $column_info['name'] . '_tempxx TO ' . $column_info['name'],
			array(
				'security_override' => true,
			)
		);
		$pmxcFunc['db_transaction']('commit');
	}
	// Finally - auto increment?!
	if (isset($column_info['auto']) && $column_info['auto'] != $old_info['auto'])
	{
		// Are we removing an old one?
		if ($old_info['auto'])
		{
			// Alter the table first - then drop the sequence.
			$pmxcFunc['db_query']('', '
				ALTER TABLE ' . $table_name . '
				ALTER COLUMN ' . $column_info['name'] . ' SET DEFAULT \'0\'',
				array(
					'security_override' => true,
				)
			);
			$pmxcFunc['db_query']('', '
				DROP SEQUENCE ' . $table_name . '_seq',
				array(
					'security_override' => true,
				)
			);
		}
		// Otherwise add it!
		else
		{
			$pmxcFunc['db_query']('', '
				CREATE SEQUENCE ' . $table_name . '_seq',
				array(
					'security_override' => true,
				)
			);
			$pmxcFunc['db_query']('', '
				ALTER TABLE ' . $table_name . '
				ALTER COLUMN ' . $column_info['name'] . ' SET DEFAULT nextval(\'' . $table_name . '_seq\')',
				array(
					'security_override' => true,
				)
			);
		}
	}

	return true;
}

/**
 * Add an index.
 *
 * @param string $table_name The name of the table to add the index to
 * @param array $index_info An array of index info (see {@link pmx_db_create_table()})
 * @param array $parameters Not used?
 * @param string $if_exists What to do if the index exists. If 'update', the definition will be updated.
 * @param string $error
 * @return boolean Whether or not the operation was successful
 */
function pmx_db_add_index($table_name, $index_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
{
	global $pmxcFunc, $db_package_log, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// No columns = no index.
	if (empty($index_info['columns']))
		return false;
	$columns = implode(',', $index_info['columns']);

	// No name - make it up!
	if (empty($index_info['name']))
	{
		// No need for primary.
		if (isset($index_info['type']) && $index_info['type'] == 'primary')
			$index_info['name'] = '';
		else
			$index_info['name'] = $table_name . implode('_', $index_info['columns']);
	}
	else
		$index_info['name'] = $table_name . $index_info['name'];

	// Log that we are going to want to remove this!
	$db_package_log[] = array('remove_index', $table_name, $index_info['name']);

	// Let's get all our indexes.
	$indexes = $pmxcFunc['db_list_indexes']($table_name, true);
	// Do we already have it?
	foreach ($indexes as $index)
	{
		if ($index['name'] == $index_info['name'] || ($index['type'] == 'primary' && isset($index_info['type']) && $index_info['type'] == 'primary'))
		{
			// If we want to overwrite simply remove the current one then continue.
			if ($if_exists != 'update' || $index['type'] == 'primary')
				return false;
			else
				$pmxcFunc['db_remove_index']($table_name, $index_info['name']);
		}
	}

	// If we're here we know we don't have the index - so just add it.
	if (!empty($index_info['type']) && $index_info['type'] == 'primary')
	{
		$pmxcFunc['db_query']('', '
			ALTER TABLE ' . $table_name . '
			ADD PRIMARY KEY (' . $columns . ')',
			array(
				'security_override' => true,
			)
		);
	}
	else
	{
		$pmxcFunc['db_query']('', '
			CREATE ' . (isset($index_info['type']) && $index_info['type'] == 'unique' ? 'UNIQUE' : '') . ' INDEX ' . $index_info['name'] . ' ON ' . $table_name . ' (' . $columns . ')',
			array(
				'security_override' => true,
			)
		);
	}
}

/**
 * Remove an index.
 *
 * @param string $table_name The name of the table to remove the index from
 * @param string $index_name The name of the index to remove
 * @param array $parameters Not used?
 * @param string $error
 * @return boolean Whether or not the operation was successful
 */
function pmx_db_remove_index($table_name, $index_name, $parameters = array(), $error = 'fatal')
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	// Better exist!
	$indexes = $pmxcFunc['db_list_indexes']($table_name, true);
	if ($index_name != 'primary')
		$index_name = $table_name . '_' . $index_name;

	foreach ($indexes as $index)
	{
		// If the name is primary we want the primary key!
		if ($index['type'] == 'primary' && $index_name == 'primary')
		{
			// Dropping primary key is odd...
			$pmxcFunc['db_query']('', '
				ALTER TABLE ' . $table_name . '
				DROP CONSTRAINT ' . $index['name'],
				array(
					'security_override' => true,
				)
			);

			return true;
		}
		if ($index['name'] == $index_name)
		{
			// Drop the bugger...
			$pmxcFunc['db_query']('', '
				DROP INDEX ' . $index_name,
				array(
					'security_override' => true,
				)
			);

			return true;
		}
	}

	// Not to be found ;(
	return false;
}

/**
 * Get the schema formatted name for a type.
 *
 * @param string $type_name The data type (int, varchar, smallint, etc.)
 * @param int $type_size The size (8, 255, etc.)
 * @param boolean $reverse If true, returns specific types for a generic type
 * @return array An array containing the appropriate type and size for this DB type
 */
function pmx_db_calculate_type($type_name, $type_size = null, $reverse = false)
{
	// Let's be sure it's lowercase MySQL likes both, others no.
	$type_name = strtolower($type_name);
	// Generic => Specific.
	if (!$reverse)
	{
		$types = array(
			'varchar' => 'character varying',
			'char' => 'character',
			'mediumint' => 'int',
			'tinyint' => 'smallint',
			'tinytext' => 'character varying',
			'mediumtext' => 'text',
			'largetext' => 'text',
		);
	}
	else
	{
		$types = array(
			'character varying' => 'varchar',
			'character' => 'char',
			'integer' => 'int',
		);
	}

	// Got it? Change it!
	if (isset($types[$type_name]))
	{
		if ($type_name == 'tinytext')
			$type_size = 255;
		$type_name = $types[$type_name];
	}
	// Numbers don't have a size.
	if (strpos($type_name, 'int') !== false)
			$type_size = null;

	return array($type_name, $type_size);
}

/**
 * Get table structure.
 *
 * @param string $table_name The name of the table
 * @param array $parameters Not used?
 * @return An array of table structure - the name, the column info from {@link pmx_db_list_columns()} and the index info from {@link pmx_db_list_indexes()}
 */
function pmx_db_table_structure($table_name, $parameters = array())
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	return array(
		'name' => $table_name,
		'columns' => $pmxcFunc['db_list_columns']($table_name, true),
		'indexes' => $pmxcFunc['db_list_indexes']($table_name, true),
	);
}

/**
 * Return column information for a table.
 *
 * @param string $table_name The name of the table to get column info for
 * @param bool $detail Whether or not to return detailed info. If true, returns the column info. If false, just returns the column names.
 * @param array $parameters Not used?
 * @return array An array of column names or detailed column info, depending on $detail
 */
function pmx_db_list_columns($table_name, $detail = false, $parameters = array())
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	$result = $pmxcFunc['db_query']('', '
		SELECT column_name, column_default, is_nullable, data_type, character_maximum_length
		FROM information_schema.columns
		WHERE table_name = \'' . $table_name . '\'
		ORDER BY ordinal_position',
		array(
			'security_override' => true,
		)
	);
	$columns = array();
	while ($row = $pmxcFunc['db_fetch_assoc']($result))
	{
		if (!$detail)
		{
			$columns[] = $row['column_name'];
		}
		else
		{
			$auto = false;
			// What is the default?
			if (preg_match('~nextval\(\'(.+?)\'(.+?)*\)~i', $row['column_default'], $matches) != 0)
			{
				$default = null;
				$auto = true;
			}
			elseif (trim($row['column_default']) != '')
				$default = strpos($row['column_default'], '::') === false ? $row['column_default'] : substr($row['column_default'], 0, strpos($row['column_default'], '::'));
			else
				$default = null;

			// Make the type generic.
			list ($type, $size) = $pmxcFunc['db_calculate_type']($row['data_type'], $row['character_maximum_length'], true);

			$columns[$row['column_name']] = array(
				'name' => $row['column_name'],
				'null' => $row['is_nullable'] ? true : false,
				'default' => $default,
				'type' => $type,
				'size' => $size,
				'auto' => $auto,
			);
		}
	}
	$pmxcFunc['db_free_result']($result);

	return $columns;
}

/**
 * Get index information.
 *
 * @param string $table_name The name of the table to get indexes for
 * @param bool $detail Whether or not to return detailed info.
 * @param array $parameters Not used?
 * @return array An array of index names or a detailed array of index info, depending on $detail
 */
function pmx_db_list_indexes($table_name, $detail = false, $parameters = array())
{
	global $pmxcFunc, $db_prefix;

	$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

	$result = $pmxcFunc['db_query']('', '
		SELECT CASE WHEN i.indisprimary THEN 1 ELSE 0 END AS is_primary,
			CASE WHEN i.indisunique THEN 1 ELSE 0 END AS is_unique,
			c2.relname AS name,
			pg_get_indexdef(i.indexrelid) AS inddef
		FROM pg_class AS c, pg_class AS c2, pg_index AS i
		WHERE c.relname = \'' . $table_name . '\'
			AND c.oid = i.indrelid
			AND i.indexrelid = c2.oid',
		array(
			'security_override' => true,
		)
	);
	$indexes = array();
	while ($row = $pmxcFunc['db_fetch_assoc']($result))
	{
		// Try get the columns that make it up.
		if (preg_match('~\(([^\)]+?)\)~i', $row['inddef'], $matches) == 0)
			continue;

		$columns = explode(',', $matches[1]);

		if (empty($columns))
			continue;

		foreach ($columns as $k => $v)
			$columns[$k] = trim($v);

		// Fix up the name to be consistent cross databases
		if (substr($row['name'], -5) == '_pkey' && $row['is_primary'] == 1)
			$row['name'] = 'PRIMARY';
		else
			$row['name'] = str_replace($table_name . '_', '', $row['name']);

		if (!$detail)
			$indexes[] = $row['name'];
		else
		{
			$indexes[$row['name']] = array(
				'name' => $row['name'],
				'type' => $row['is_primary'] ? 'primary' : ($row['is_unique'] ? 'unique' : 'index'),
				'columns' => $columns,
			);
		}
	}
	$pmxcFunc['db_free_result']($result);

	return $indexes;
}

?>
