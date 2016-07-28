<?php
// Version: 2.1 Beta 4; ToolsHelp

global $helptxt;

// Admin help messages
$helptxt['dont_use_lightbox'] = 'If <b>enabled</b>, images and attaches in messages can be displayed enlarged.<br>If you have more than one image in a message, it will be shown like a gallery.<br>You can also disable any image or attach by adding a <b>expand=off</b> to the IMG or ATTACH bbc code, like [img expand=off] or [attach expand=off]';
$helptxt['enable_quick_reply'] = 'This setting allows all users to using the Quick Reply box on the message index page.';
$helptxt['add_favicon_to_links'] = 'This settings add a favicon (if the site have one) to each link with the class "bbc_link".';

$helptxt['ecl_enabled'] = 'This make your PortaMx Forum compatible with the <b>EU Cookie Law</b>.<br>
	If enabled, any visitor (except spider) must accept the storage of cookies before he can browse the forum.<br>
	More information you find on <a href="http://ec.europa.eu/ipg/basics/legal/cookies/index_en.htm" target="_blank">European Commission</a>';
$helptxt['ecl_nomodal'] = 'Normaly the Forum are not accessible until ECL is accepted.<br>
	If you enable the <b>none modal mode</b>, the site is accessible and a Vistor can simple browse the forum.
	<b>Note, that is this case any additional modification or adsense content can store cookies!</b>';
$helptxt['ecl_nomodal_mobile'] = 'On Mobile devices the ECL mode is normaly switched to <b>modal mode</b>. Here you can disable this, so the <b>none modal mode</b> is used.';
$helptxt['ecl_topofs'] = 'Here you can set the top position for the ECL overlay.';
?>