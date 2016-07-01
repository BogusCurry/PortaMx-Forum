<?php

/**
 * This file contains database functions specific to search related activity.
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
 *  Add the file functions to the $pmxcFunc array.
 */
function db_search_init()
{
	global $pmxcFunc;

	if (!isset($pmxcFunc['db_search_query']) || $pmxcFunc['db_search_query'] != 'pmx_db_query')
		$pmxcFunc += array(
			'db_search_query' => 'pmx_db_query',
			'db_search_support' => 'pmx_db_search_support',
			'db_create_word_search' => 'pmx_db_create_word_search',
			'db_support_ignore' => true,
		);
}

/**
 * This function will tell you whether this database type supports this search type.
 *
 * @param string $search_type The search type.
 * @return boolean Whether or not the specified search type is supported by this db system
 */
function pmx_db_search_support($search_type)
{
	$supported_types = array('fulltext');

	return in_array($search_type, $supported_types);
}

/**
 * Highly specific function, to create the custom word index table.
 *
 * @param string $size The size of the desired index.
 */
function pmx_db_create_word_search($size)
{
	global $pmxcFunc;

	if ($size == 'small')
		$size = 'smallint(5)';
	elseif ($size == 'medium')
		$size = 'mediumint(8)';
	else
		$size = 'int(10)';

	$pmxcFunc['db_query']('', '
		CREATE TABLE {db_prefix}log_search_words (
			id_word {raw:size} unsigned NOT NULL default {string:string_zero},
			id_msg int(10) unsigned NOT NULL default {string:string_zero},
			PRIMARY KEY (id_word, id_msg)
		) ENGINE=InnoDB',
		array(
			'string_zero' => '0',
			'size' => $size,
		)
	);
}

?>