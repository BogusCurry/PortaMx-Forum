<?php
/**
 * This file has all the SEF (Search Engine Friendly) functions.
 *
 * PortaMx Forum
 * @package PortaMx
 * @author PortaMx
 * @copyright 2016 PortaMx
 *
 * @version 2.1 Beta 5
 *
 * Developer of the Original Code is Matt Zuba.
 * License: MPL 1.1
*/

if (!defined('PMX'))
	die('No direct access...');

/*************************************
* Initiate the SEF enging, then convert
* called after: integrate_pre_load
***************************************/
function pmxsef_convertSEF()
{
	global $boardurl, $modSettings, $scripturl, $cache_enable, $pmxSEF, $pmxCacheFunc;

	// if cache disabled, disable SEF
	if(empty($cache_enable))
		$modSettings['sef_enabled'] = 0;

	if(empty($modSettings['sef_enabled']) || PMX == 'SSI')
	{
		if(isset($_GET['q']))
			unset($_GET['q']);
		return;
	}

	pmxsef_LoadSettings();

	// Make sure we know the URL of the current request.
	parse_str(preg_replace('~&(\w+)(?=&|$)~', '&$1=', strtr(str_replace('q=', '', $_SERVER['QUERY_STRING']), array(';?' => '&', '/;' => '/', ';' => '&', '%00' => '', "\0" => ''))), $_GET);

	$scripturl = $boardurl . '/index.php';
	if(empty($_SERVER['REQUEST_URI']))
		$_SERVER['REQUEST_URL'] = $scripturl . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
	elseif(preg_match('~^([^/]+//[^/]+)~', $scripturl, $match) == 1)
		$_SERVER['REQUEST_URL'] = $match[1] . $_SERVER['REQUEST_URI'];
	else
		$_SERVER['REQUEST_URL'] = $_SERVER['REQUEST_URI'];

	// replace a simple domain.tld/? to /index.php?
	if(strpos($_SERVER['REQUEST_URL'], 'index.php') === false && strpos($_SERVER['REQUEST_URL'], $boardurl .'/?') !== false)
		$_SERVER['REQUEST_URL'] = str_replace($boardurl .'/?', $scripturl .'?', $_SERVER['REQUEST_URL']);

	// redirect on bord/topic (like called from external), return if is a ignore action, convert other
	if(strpos($_SERVER['REQUEST_URL'], $scripturl) !== false)
	{
		if(isset($_GET['action']) && in_array($_GET['action'], $pmxSEF['ignoreactions']))
			return;

		// topic or board requested .. redirect
		if(!isset($_GET['action']) && isset($_GET['board']) || isset($_GET['topic']))
			redirectexit($_SERVER['REQUEST_URL']);

		// else convert the query
		$_SERVER['REQUEST_URL'] = create_sefurl($_SERVER['REQUEST_URL']);
	}

	// special handling for likes
	$_SERVER['REQUEST_URL'] = preg_replace(array('~;js=~', '~\?_=~'), array('js/', '/_/'), $_SERVER['REQUEST_URL']);
	// convert the SEF query
 	$_GET = pmxsef_query(rawurldecode(ltrim(str_replace($boardurl, '', $_SERVER['REQUEST_URL']), '/')));
	$_SERVER['QUERY_STRING'] = pmxsef_build_query($_GET);

	// check if a topic subject changed
	if(isset($_GET['action']) && $_GET['action'] == 'post2' && !empty($_POST) && !isset($_POST['preview']) && isset($pmxSEF['TopicNameList'][$_POST['topic']]))
	{
		$temp = explode('/', $pmxSEF['TopicNameList'][$_POST['topic']]);
		unset($pmxSEF['TopicNameList'][$_POST['topic']]);
		$pmxSEF['TopicNameList'][$_POST['topic']] = CheckDupe($temp[0] .'/', $_POST['subject'], array('Topic'));
		$pmxCacheFunc['put']('sef_topiclist', $pmxSEF['TopicNameList'], 3600);
	}
}

/**************************************
/* Load the SEF settings settings
***************************************/
function pmxsef_LoadSettings()
{
	global $pmxSEF, $pmxCacheFunc, $modSettings;

	if(($pmxSEF = $pmxCacheFunc['get']('sef_settings')) === null)
	{
		$pmxSEF = array(
			'actions' => array_unique(explode(',', $modSettings['sef_actions'])),
			'ignoreactions' => array_unique(array_merge(array('admin', 'viewpmxfile', 'uploadAttach'), explode(',', $modSettings['sef_ignoreactions']))),
			'stripchars' => array_diff(explode(',', $modSettings['sef_stripchars']), array(trim($modSettings['sef_spacechar']))),
			'spacechar' => trim($modSettings['sef_spacechar']),
			'lowercase' => $modSettings['sef_lowercase'],
			'autosave' => $modSettings['sef_autosave'],
		);
		$pmxCacheFunc['put']('sef_settings', $pmxSEF, 86400);
	}
	$pmxSEF['allactions'] = array_filter(array_unique(array_merge($pmxSEF['actions'], $pmxSEF['ignoreactions'])));
	$modSettings['queryless_urls'] = 0;
}

/**************************************
* convert the requested SEF url
* called from pmxsef_convertSEF
***************************************/
function pmxsef_query($query)
{
	global $boardurl, $modSettings, $pmxSEF;

	$querystring = $queryfragment = array();

	if(!empty($query) && $query != '/')
	{
		// cleanup the url
		$url_array = explode('/', trim(str_replace(array(';', '..'), '', $query), '/'));

		// check the actions
		if(array_search(current($url_array), $pmxSEF['allactions'], true) !== false)
		{
			$querystring['action'] = array_shift($url_array);

			// check for subaction
			$fnd = array_search('sa', $url_array, true);
			if($fnd !== false && isset($url_array[$fnd +1]))
			{
				$querystring['sa'] = $url_array[$fnd +1];
				array_splice($url_array, $fnd, 2);
			}

			// special handling for theme token
			elseif($querystring['action'] == 'theme')
			{
				unset($querystring['action']);
				array_unshift($url_array, $tmp);
			}
		}

		if(!empty($url_array))
		{
			// categorie ?
			getCategorieNameList();
			if(($value = array_search(current($url_array), $pmxSEF['CategorieNameList'], true)) !== false)
			{
				$querystring['c'] = $value;
				array_shift($url_array);
			}

			// not a category .. check board or topic
			else
			{
				// board or board/topic ?
				$value = false;
				if(count($url_array) >= 1)
				{
					if(empty($pmxSEF['BoardNameList']))
						getBoardNameList();

					if(($boardID = array_search(current($url_array), $pmxSEF['BoardNameList'], true)) !== false)
					{
						$boardName = current($url_array);
						$page = isset($url_array[1]) && is_numeric($url_array[1]) ? $url_array[1] : '0';
						$querystring['board'] = $boardID .'.'. $page;
						array_splice($url_array, 0, 1 + intval(!empty($page)));

						// if a topic given ?
						if(count($url_array) >= 1)
						{
							if(empty($pmxSEF['TopicNameList']))
								getTopicNameList();

							if(($topicID = array_search($boardName .'/'. current($url_array), $pmxSEF['TopicNameList'], true)) === false)
							{
								// if directly called, get topics in the board
								getTopicNameList(array(), $boardID);
								$topicID = array_search($boardName .'/'. current($url_array), $pmxSEF['TopicNameList'], true);
							}

							// have a topic?
							if($topicID !== false)
							{
								$page = isset($url_array[1]) && preg_match('/msg([0-9]+)|[0-9]+/', $url_array[1], $msgPage) > 0 && $msgPage[0] == $url_array[1] ? strval($msgPage[0]) : '0';
								$querystring['topic'] = $topicID .'.'. $page;
								array_splice($url_array, 0, 1 + intval(!empty($page)));

								//finally remove the board id
 								unset($querystring['board']);
							}
						}
					}
				}
			}
		}

		// username ?
		if(count($url_array) > 0)
		{
			if(empty($pmxSEF['UserNameList']))
				getUserNameList();
			if(($user = array_search(current($url_array), $pmxSEF['UserNameList'], true)) !== false)
			{
				$querystring['u'] = $user;
				array_shift($url_array);
			}
		}

		// do the rest
		while(!empty($url_array))
			$querystring[array_shift($url_array)] = array_shift($url_array);
	}

	return $querystring;
}

/*******************************************
* convert redirected urls to SEF format
* called before: integrate_redirect
********************************************/
function pmxsef_Redirect(&$setLocation)
{
	global $boardurl, $pmxSEF, $pmxCacheFunc, $modSettings;

	if(empty($modSettings['sef_enabled']))
		return;

	// Only do this if it's an URL for this board
	if(strpos($setLocation, $boardurl) !== false)
	{
		$setLocation = create_sefurl($setLocation);

		// Check to see if we need to update the actions lists
		if(!empty($pmxSEF['autosave']) && count(explode(',', $modSettings['sef_actions'])) != count($pmxSEF['actions']))
		{
			$changeArray['sef_actions'] = implode(',', array_filter(array_unique($pmxSEF['actions'])));
			updateSettings($changeArray);
			$pmxCacheFunc['drop']('sef_settings');
		}
		$pmxSEF['redirect'] = true;
	}
}

/**********************************
* convert XML urls to SEF format
* called before: integrate_exit
***********************************/
function pmxsef_XMLOutput($do_footer)
{
	global $pmxSEF, $modSettings;

	if(empty($modSettings['sef_enabled']))
		return;

	if(empty($do_footer) && empty($pmxSEF['redirect']))
	{
		$temp = ob_get_contents();

		ob_end_clean();
		ob_start('ob_pmxsef');

		echo $temp;
	}
}

/********************************************
* convert eMail urls to SEF format
* called before: integrate_outgoing_email
*********************************************/
function pmxsef_EmailOutput(&$subject, &$message, &$header)
{
	global $modSettings;

	if(!empty($modSettings['sef_enabled']))
	{
		// We're just fixing the subject and message
		$subject = ob_pmxsef($subject);
		$message = ob_pmxsef($message);
	}

	// We must return true, otherwise we fail!
	return true;
}

/********************************************
* convert urls to SEF format
* called before integrate_fix_url
*********************************************/
function pmxsef_fixurl($url)
{
	global $modSettings;

	if(empty($modSettings['sef_enabled']))
		return $url;

	$url = create_sefurl($url);
	return $url;
}

/**************************************************
* Convert all urls in the outbuffer to SEF format
* called after integrate_buffer
***************************************************/
function ob_pmxsef($buffer)
{
	global $scripturl, $boardurl, $pmxSEF, $pmxCacheFunc, $modSettings;

	if(isset($_REQUEST['jscook']) || empty($modSettings['sef_enabled']))
		return $buffer;

	// fix expandable pagelinks
	$buffer = str_replace('/index.php\'+\'?', '/index.php?', $buffer);

	// Get all categories..
	getCategorieNameList();

	// Get all bords..
	getBoardNameList();

	// Get topics..
	$matches = array();
	preg_match_all('~\b' . preg_quote($scripturl) . '.*?topic=([0-9\.]+)~', $buffer, $matches);
	if(!empty($matches[1]))
		getTopicNameList(array_unique($matches[1]));

	// Get user..
	$matches = array();
	preg_match_all('~\b'. preg_quote($scripturl) .'.*?u=([0-9]+)~', $buffer, $matches);
	if(!empty($matches[1]))
		getUserNameList(array_unique($matches[1]));

	// Do the rest of the URLs, skip admin urls
	$matches = array();
	preg_match_all('~\b('. preg_quote($scripturl) .'(?!\?action=admin)[-a-zA-Z0-9+&@#/%?=\~_|!:,.;\[\]]*[-a-zA-Z0-9+&@#/%=\~_|\[\]]?)([^-a-zA-Z0-9+&@#/%=\~_|])~', $buffer, $matches);
	if(!empty($matches[1]))
	{
		$replacements = array();
		foreach($matches[1] as $i => $url)
		{
			$replace = create_sefurl($url);
			if($url != $replace)
				$replacements[$matches[0][$i]] = $replace . str_replace(';', '', $matches[2][$i]);
		}
		$buffer = str_replace(array_keys($replacements), array_values($replacements), $buffer);
	}

	//	fix expandable page links
	preg_match_all('~\b(pmx_scripturl)(.*?)(topic=)([0-9\.]+)([\%\$d0-9]+)~', $buffer, $matches, PREG_SET_ORDER);
	if(!empty($matches[0]))
	{
		$replacements = array();
		foreach($matches as $id => $data)
		{
			$data[5] = str_replace('.', '/', $data[5]);
			$replacements[$data[0]] = $data[1] . str_replace('?', '', $data[2]) . getTopicName(intval($data[4]) .'.') . $data[5] .'/';
		}
		$buffer = str_replace(array_keys($replacements), array_values($replacements), $buffer);
	}

	// Check if we need to update the actions lists
	if(!empty($pmxSEF['autosave']) && count(explode(',', $modSettings['sef_actions'])) != count($pmxSEF['actions']))
	{
		$changeArray['sef_actions'] = implode(',', array_filter(array_unique($pmxSEF['actions'])));
		updateSettings($changeArray);
		$pmxCacheFunc['drop']('sef_settings');
	}

	// done
	return $buffer;
}

/******************************************
* redirected for unknow urls
* called internal
*******************************************/
function pmxsef_redir_perm($url)
{
	redirectexit($url);
}

/************************************************
* convert urls to SEF format
* called by sef_fixRedirectUrl, ob_pmxsef
*************************************************/
function create_sefurl($url)
{
	global $boardurl, $modSettings, $pmxSEF;

	if(empty($modSettings['sef_enabled']))
		return $url;

	// load settings
	pmxsef_LoadSettings();

	// Init..
	$sefstring = $sefstring1 = $sefstring2 = '';

	// Get the query string
	$params = array();
	$url_parts = parse_url($url);

	// security .. check illegal url's
	parse_str(!empty($url_parts['query']) ? preg_replace('~&(\w+)(?=&|$)~', '&$1=', strtr($url_parts['query'], array('&amp;' => '&', ';' => '&'))) : '', $params);
	if(!empty($params))
	{
		// check ingnore actions
		if(!empty($params['action']) && in_array($params['action'], $pmxSEF['ignoreactions']))
			return $url;

		// actions
		if(isset($params['action']))
		{
			if(!in_array($params['action'], array_merge($pmxSEF['actions'], array('theme', 'language'))))
			{
				preg_match('/[a-zA-Z0-9\_\-]+/', $params['action'], $action);
				if(!empty($action[0]))
					$pmxSEF['actions'][] = $action[0];
			}
			$sefstring .= $params['action'] .'/';
			unset($params['action']);
		}

		// categories
		if(isset($params['c']))
		{
			$sefstring .= getCategorieName($params['c']);
			unset($params['c']);
		}

		// boards
		elseif(isset($params['board']))
		{
			$sefstring .= getBoardName($params['board']);
			$brdID = explode('.', $params['board']);
			unset($params['board']);
		}

		// topics
		elseif(isset($params['topic']))
		{
			$sefstring .= getTopicName($params['topic']);
			unset($params['topic']);
		}

		// user
		if(isset($params['u']))
		{
			$sefstring .= ($params['u'] == 'all' ? $params['u'] .'/' : getUserName($params['u']));
			unset($params['u']);
		}

		// do the rest
		foreach($params as $key => $value)
		{
			if($key == 'start')
				$sefstring2 .= ($value != '' ? $key .'/'. $value .'/' : '');

			elseif(is_array($value))
				$sefstring1 .= $key .'['. key($value) .']/'. $value[key($value)] .'/';

			else
				$sefstring1 .= $key .'/'. $value .'/';
		}

		// Build the URL
		$sefstring .= $sefstring1 . $sefstring2;
	}
	return $boardurl .'/'. (!empty($sefstring) ? rtrim($sefstring, '/') .'/' : '') . (!empty($url_parts['fragment']) ? '#' . $url_parts['fragment'] : '');
}

/**
* get the SEF Categorie name for id
* called from: create_sefurl
**/
function getCategorieName($id)
{
	global $pmxSEF;

	if(!empty($id))
	{
		getCategorieNameList();

		if(!empty($pmxSEF['CategorieNameList'][$id]))
			return $pmxSEF['CategorieNameList'][$id] .'/';
	}
	return '';
}

/**
* convert categorie names to SEF
* called from: getCategorieName, pmxsef_query
**/
function getCategorieNameList()
{
	global $pmxSEF, $pmxcFunc, $pmxCacheFunc;

	$pmxSEF['CategorieNameList'] = $pmxCacheFunc['get']('sef_categorielist');
	if(empty($pmxSEF['CategorieNameList']))
	{
		$request = $pmxcFunc['db_query']('', '
			SELECT id_cat, name
			FROM {db_prefix}categories',
			array()
		);
		while ($row = $pmxcFunc['db_fetch_assoc']($request))
			$pmxSEF['CategorieNameList'][$row['id_cat']] = CheckDupe('', $row['name'], array('Categorie', 'Board', 'Topic'));

		$pmxcFunc['db_free_result']($request);
		$pmxCacheFunc['put']('sef_categorielist', $pmxSEF['CategorieNameList'], 3600);
	}
}

/**
* convert board names to SEF
* called from: getBoardName, pmxsef_query
**/
function getBoardNameList()
{
	global $pmxSEF, $pmxcFunc, $pmxCacheFunc;

	$pmxSEF['BoardNameList'] = $pmxCacheFunc['get']('sef_boardlist');
	if(empty($pmxSEF['BoardNameList']))
	{
		$request = $pmxcFunc['db_query']('', '
			SELECT id_board, name
			FROM {db_prefix}boards',
			array()
		);
		while ($row = $pmxcFunc['db_fetch_assoc']($request))
			$pmxSEF['BoardNameList'][$row['id_board']] = CheckDupe('', $row['name'], array('Board', 'Categorie', 'Topic'));

		$pmxcFunc['db_free_result']($request);
		$pmxCacheFunc['put']('sef_boardlist', $pmxSEF['BoardNameList'], 3600);
	}
}

/**
* get the SEF board name for id
* called from: create_sefurl
**/
function getBoardName($id)
{
	global $pmxSEF;

	if(!empty($id))
	{
		$id = (strpos($id, '.') === false ? $id .'.0' : $id);
		@list($sefboard, $sefpage) = explode('.', $id);
		getBoardNameList();

		if(!empty($pmxSEF['BoardNameList'][$sefboard]))
			return $pmxSEF['BoardNameList'][$sefboard] .(!empty($sefpage) ? '/'. str_replace('\\', '', $sefpage) : '') .'/';
	}
	return '';
}

/**
* convert topic subjects to SEF
* called from: getTopicName, create_sefurl
**/
function getTopicNameList($seftopics = array(), $boardID = null)
{
	global $pmxSEF, $pmxcFunc, $pmxCacheFunc, $boarddir;

	// make integers
	array_walk($seftopics, create_function('&$v, $k', '$v = intval(trim($v));'));

	$pmxSEF['TopicNameList'] = $pmxCacheFunc['get']('sef_topiclist');
	if(empty($pmxSEF['TopicNameList']))
		$pmxSEF['TopicNameList'] = array();

	if(!empty($boardID))
	{
		$board = getBoardName($boardID);
		$request = $pmxcFunc['db_query']('', '
			SELECT t.id_topic, m.subject, t.id_board
			FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
			WHERE t.id_board = {int:board}',
			array('board' => $boardID)
		);
		while($row = $pmxcFunc['db_fetch_assoc']($request))
		{
			if(empty($pmxSEF['TopicNameList'][$row['id_topic']]))
				$pmxSEF['TopicNameList'][$row['id_topic']] = CheckDupe($board, $row['subject'], array('Topic'));
		}
		$pmxcFunc['db_free_result']($request);
		$pmxCacheFunc['put']('sef_topiclist', $pmxSEF['TopicNameList'], 3600);
	}
	else
	{
		$notcached = array_diff($seftopics, array_keys($pmxSEF['TopicNameList']));
		if(!empty($notcached))
		{
			$boardID = '';
			$request = $pmxcFunc['db_query']('', '
				SELECT t.id_topic, m.subject, t.id_board
				FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
				WHERE t.id_topic IN ({array_int:topics})
				ORDER by t.id_board',
				array('topics' => $notcached)
			);
			while($row = $pmxcFunc['db_fetch_assoc']($request))
			{
				if($boardID != $row['id_board'])
				{
					$boardID = $row['id_board'];
					$board = getBoardName($row['id_board']);
				}
				$pmxSEF['TopicNameList'][$row['id_topic']] = CheckDupe($board, $row['subject'], array('Topic'));
			}
			$pmxcFunc['db_free_result']($request);
			$pmxCacheFunc['put']('sef_topiclist', $pmxSEF['TopicNameList'], 3600);
		}
	}
}

/**
* get the SEF topic name for id
* called from: ob_pmxsef, create_sefurl
**/
function getTopicName($id)
{
	global $pmxSEF;

	if(!empty($id))
	{
		@list($seftopic, $sefpage) = explode('.', $id);
		getTopicNameList(array($seftopic));

		if(!empty($pmxSEF['TopicNameList'][$seftopic]))
			return $pmxSEF['TopicNameList'][$seftopic] . (!empty($sefpage) ? '/'. $sefpage : '') .'/';
	}
	return '';
}

/**
* convert user real name to SEF
* called from: pmxsef_query
**/
function getUserNameList($user = array())
{
	global $pmxSEF, $pmxcFunc, $pmxCacheFunc;

	$pmxSEF['UserNameList'] = $pmxCacheFunc['get']('sef_userlist');
	if(empty($pmxSEF['UserNameList']))
		$pmxSEF['UserNameList'] = array();

	array_walk($user, create_function('&$v,$k', '$v = intval(trim($v));'));
	$notcached = array_diff($user, array_keys($pmxSEF['UserNameList']), array(''));
	if(!empty($notcached))
	{
		$request = $pmxcFunc['db_query']('', '
			SELECT id_member, real_name
			FROM {db_prefix}members
			WHERE id_member IN ({array_int:members})',
			array('members' => $notcached)
		);

		while($row = $pmxcFunc['db_fetch_assoc']($request))
			$pmxSEF['UserNameList'][$row['id_member']] = pmxsef_encode($row['real_name']);

		$pmxcFunc['db_free_result']($request);
		$pmxCacheFunc['put']('sef_userlist', $pmxSEF['UserNameList'], 3600);
	}
}

/**
* get the SEF user name for id
* called from: create_sefurl
**/
function getUserName($id)
{
	global $pmxSEF;

	if(!empty($id))
	{
		getUserNameList(array($id));

		if(!empty($pmxSEF['UserNameList'][$id]))
			return $pmxSEF['UserNameList'][$id] .'/';
	}
	return '';
}

/**
* Dupe check
* called from xxxNameList
*/
function CheckDupe($prefix, $search, $listNames)
{
	global $pmxSEF;

	$name = pmxsef_encode($search);
	$pmxSEF['#dupes#'] = !isset($pmxSEF['#dupes#']) ? 0 : $pmxSEF['#dupes#'];
	$dupes = array_search($name, $pmxSEF['allactions'], true) ? 1 : 0;

	foreach($listNames as $LName)
	{
		if(isset($pmxSEF[$LName .'NameList']) && is_array($pmxSEF[$LName .'NameList']) && count($pmxSEF[$LName .'NameList']) > 0)
		{
			$done = false;
			do {
				$searchName = (empty($prefix) ? '' : $prefix) . (empty($dupes) ? $name : $name . strval($dupes));
				if(($key = array_search($searchName, $pmxSEF[$LName . 'NameList'], true)) !== false && $pmxSEF[$LName . 'NameList'][$key] == $searchName)
					$dupes = ++$pmxSEF['#dupes#'];
				else
					$done = true;
			} while (!$done);
		}
	}
	$pmxSEF['#dupes#'] = $dupes > $pmxSEF['#dupes#'] ? $dupes : $pmxSEF['#dupes#'];
	return $prefix . (empty($dupes) ? $name : $name . strval($dupes));
}

/**
* find and convert a url to Forum format
* called from: pmxsef_query
**/
function getUrlParams($key, $hasval, &$url, &$result)
{
	$pos = array_search($key, $url);
	if($pos === false)
		return !empty($url);

	$result[$key] = !empty($hasval) ? $url[$pos +1] : '';
	array_splice($url, $pos, intval($hasval) +1);
	return !empty($url);
}

/**
* build the "normal" querystring
* called from: pmxsef_query
**/
function pmxsef_build_query($data, $prefix = '', $sep = ';')
{
	$ret = array();
	foreach ((array) $data as $k => $v)
	{
		$k = urlencode($k);
		if(is_int($k) && !empty($prefix))
			$k = $prefix . $k;
		if(is_array($v) || is_object($v))
			array_push($ret, pmxsef_build_query($v, '', $sep));
		elseif($v == '')
			array_push($ret, $k);
		else
			array_push($ret, $k .'='. urlencode($v));
	}

	if(empty($sep))
		$sep = ini_get("arg_separator.output");

	return implode($sep, $ret);
}

/**
* convert a string for SEF url's
**/
function pmxsef_encode($string)
{
	global $modSettings, $sourcedir, $pmxSEF, $txt;
	static $utf8_db = array();
	static $isWindows = null;

	$string = trim($string);
	if(empty($string))
		return '';

	// we are on a window server like xampp ?
	if(!is_bool($isWindows))
		$isWindows = ini_get('extension_dir') && strpos(ini_get('extension_dir'), ':\\') !== false;

	// make all strings to ISO-8859-1 or UTF-8 and if not, convert to UTF-8
	$char_set = empty($modSettings['global_character_set']) ? $txt['lang_character_set'] : $modSettings['global_character_set'];
	setlocale(LC_CTYPE, $txt['lang_locale']);

	if($char_set != 'ISO-8859-1' || $char_set != 'UTF-8')
	{
		if(function_exists('mb_convert_encoding'))
			$string = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string, 'UTF-8, ISO-8859-1, ISO-8859-15, Windows-1251, Windows-1252, Windows-1254', true));

		elseif(function_exists('iconv'))
			$string = iconv($char_set, 'UTF-8//TRANSLIT//IGNORE', $isWindows ? utf8_encode($string) : $string);

		elseif(function_exists('unicode_decode'))
			$string = unicode_decode($string, $char_set);
	}

	$character = 0;
	$result = '';
	$length = strlen($string);
	$i = 0;

	while($i < $length)
	{
		$charInt = ord($string[$i++]);
		// normal Ascii character
		if(($charInt & 0x80) == 0)
			$character = $charInt;

		// Two byte unicode
		elseif(($charInt & 0xE0) == 0xC0)
		{
			$temp1 = ord($string[$i++]);
			if (($temp1 & 0xC0) != 0x80)
				$character = 63;
			else
				$character = ($charInt & 0x1F) << 6 | ($temp1 & 0x3F);
		}

		// Three byte ..
		elseif(($charInt & 0xF0) == 0xE0)
		{
			$temp1 = ord($string[$i++]);
			$temp2 = ord($string[$i++]);
			if (($temp1 & 0xC0) != 0x80 || ($temp2 & 0xC0) != 0x80)
				$character = 63;
			else
				$character = ($charInt & 0x0F) << 12 | ($temp1 & 0x3F) << 6 | ($temp2 & 0x3F);
		}

		// Four byte ..
		elseif(($charInt & 0xF8) == 0xF0)
		{
			$temp1 = ord($string[$i++]);
			$temp2 = ord($string[$i++]);
			$temp3 = ord($string[$i++]);
			if (($temp1 & 0xC0) != 0x80 || ($temp2 & 0xC0) != 0x80 || ($temp3 & 0xC0) != 0x80)
				$character = 63;
			else
				$character = ($charInt & 0x07) << 18 | ($temp1 & 0x3F) << 12 | ($temp2 & 0x3F) << 6 | ($temp3 & 0x3F);
		}

		// Thats wrong... use ?
		else
			$character = 63;

		// get the codepage for this character.
		$charBank = $character >> 8;
		if(!isset($utf8_db[$charBank]))
		{
			// Load up the codepage if it's not already in memory
			$cpFile = $sourcedir . '/sefcodepages/x' . sprintf('%02x', $charBank) . '.php';
			if(file_exists($cpFile))
				include_once($cpFile);
			else
				$utf8_db[$charBank] = array();
		}

		$finalChar = $character & 255;
		$result .= isset($utf8_db[$charBank][$finalChar]) ? $utf8_db[$charBank][$finalChar] : '?';
	}

	$result = trim(str_replace($pmxSEF['stripchars'], '', $result), "\t\r\n\0\x0B .");
	$result = preg_replace('~\s+~', $pmxSEF['spacechar'], $result);
	$result = str_replace(array('%2F','%2C','%27','%60', ','), '', $result);
	$result = str_replace(array($pmxSEF['spacechar'], '.'), '+', $result);
	$result = preg_replace('~(\+)+~', $pmxSEF['spacechar'], $result);
	return !empty($pmxSEF['lowercase']) ? strtolower($result) : $result;
}
?>