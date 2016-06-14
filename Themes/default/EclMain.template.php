<?php
/**
 * Template for modal and none modal ecl accept.
 *
 * PortaMx Forum
 * @package PortaMx
 * @author PortaMx http://portamx.com
 * @copyright 2016 Portamx
 * @license BSD
 *
 * @version 2.1 Beta 4
 */

function template_eclmain_above()
{
	global $context, $modSettings, $settings, $language, $cookiename, $txt;

	$ecl_cookie_time = strtotime('+3 month') .'000';
	$replaces = array('@host@' => $_SERVER['SERVER_NAME'], '@cookie@' => $cookiename, '@site@' => $context['forum_name']);
	echo '
	<div id="ecl_outer" style="top:'. $modSettings['ecl_topofs'] .'px;">
		<div id="ecl_inner" class="ecl_outer">';

	echo '
			<div class="ecl_head">
				'. $txt['ecl_needAccept'] . $txt['ecl_device'][$modSettings['isMobile']] .'
			</div>
			<div class="ecl_accept">
				<input type="button" name="accept" value="'. $txt['ecl_button'] .'" onclick="smfCookie(\'set\', \'eclauth\', \'\', \'ecl\');window.location.href=smf_scripturl;" />&nbsp;
				<input id="privbut" class="eclbutclose" type="button" name="accept" value="'. $txt['ecl_privacy'] .'" title="'. $txt['ecl_privacy_ttlopen'] .'" onclick="show_eclprivacy()" />';

	if(empty($modSettings['ecl_nomodal']) || (!empty($modSettings['isMobile']) && empty($modSettings['ecl_nomodal_mobile'])))
		echo '
				<div class="eclmodal"><b id="eclmodal">&nbsp;'. $txt['ecl_agree'] .'&nbsp;</b></div>';

	echo '
			</div>
			<div id="ecl_privacy" style="display:none">';

	$privacyfile = substr($context['languages'][$language]['location'], 0, strrpos($context['languages'][$language]['location'], '/')) .'/EclPrivacynotice.'. $context['languages'][$language]['filename'] .'.php';
	if(file_exists($privacyfile))
	{
		include_once($privacyfile);

		echo '
				<div id="ecl_privacytext">
			'. strtr($txt['ecl_header'], $replaces) .'
					<table class="ecl_table">';

		foreach($txt['ecl_headrows'] as $ecltextrows)
		{
			echo '
						<tr>';

			foreach($ecltextrows as $ecltext)
				echo '
						<td>'. strtr($ecltext, $replaces) .'</td>';

			echo '
						</tr>';
		}

		echo '
					</table>
					<br />';

		echo '
			'. $txt['ecl_footertop'] .'
					<table class="ecl_table">';

		$number = 1;
		foreach($txt['ecl_footrows'] as $ecltext)
		{
			echo '
						<tr>
							<td>'. $number .'.</td>
							<td>'. $ecltext .'</td>
						</tr>';

			$number++;
		}

		echo '
					</table>';

		echo '
			'. $txt['ecl_footer'] .'
				</div>';
	}
	else
		echo '
				<div>'. $txt['ecl_privacy_failed'] .'</div>';

	echo '
			</div>
		</div>
	</div>
	<script type="text/javascript">
		function show_eclprivacy()
		{
			if(document.getElementById("ecl_privacy"))
			{
				if(document.getElementById("ecl_privacy").style.display == "none")
				{
					document.getElementById("ecl_privacy").style.maxHeight = window.innerHeight - (35 + eclofsTop + document.getElementById("ecl_outer").clientHeight) +"px";
					$(document.getElementById("ecl_privacy")).slideDown(400, function(){document.getElementById("privbut").className="eclbutopen";document.getElementById("privbut").title = "'. $txt['ecl_privacy_ttlclose'] .'"});
				}
				else
					$(document.getElementById("ecl_privacy")).slideUp(400, function(){document.getElementById("privbut").className="eclbutclose";document.getElementById("privbut").title = "'. $txt['ecl_privacy_ttlopen'] .'"});
			}
		}
		function eclResize()
		{
			if(typeof eclOverlay != "undefined")
			{
				var bodywith = document.getElementsByTagName("body")[0].clientWidth;
				var forumwidth = document.getElementById("wrapper").clientWidth;
				if(bodywith == forumwidth)
					forumwidth = forumwidth -4;
				var LRpos = (bodywith - forumwidth) / 2;
				document.getElementById("ecl_outer").style.right = Math.floor(LRpos) +"px";
				document.getElementById("ecl_outer").style.left = Math.floor(LRpos) +"px";
				window.$("#ecl_outer").delay(100).fadeIn(200);
			}
		}
		window.onresize = eclResize;
		$(document).ready(eclResize);
	</script>';
}

function template_eclmain_below(){}
?>