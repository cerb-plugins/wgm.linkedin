<?php
$db = DevblocksPlatform::getDatabaseService();
$logger = DevblocksPlatform::getConsoleLog();
$settings = DevblocksPlatform::getPluginSettingsService();
$tables = $db->metaTables();

$consumer_key = $settings->get('wgm.linkedin', 'consumer_key', null);
$consumer_secret = $settings->get('wgm.linkedin', 'consumer_secret', null);

if(!is_null($consumer_key) || !is_null($consumer_secret)) {
	$credentials = [
		'consumer_key' => $consumer_key,
		'consumer_secret' => $consumer_secret,
	];
	
	$settings->set('wgm.linkedin', 'credentials', $credentials, true, true);
	$settings->delete('wgm.linkedin', ['consumer_key','consumer_secret']);
}

return TRUE;