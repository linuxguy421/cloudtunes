<?php
error_reporting(0);
$m = new Memcached();
$m->addServer('10.10.1.128', 31211) or die ("Cannot connect to memcached!");
$json = file_get_contents("http://10.10.1.128:30420/status-json.xsl");
$parsed_json = json_decode($json);
$art = $parsed_json->{'icestats'}->{'source'}->{'artist'};
$title = $parsed_json->{'icestats'}->{'source'}->{'title'};
$genre = $parsed_json->{'icestats'}->{'source'}->{'genre'};
$bitrate = $parsed_json->{'icestats'}->{'source'}->{'ice-bitrate'};
$description = $parsed_json->{'icestats'}->{'source'}->{'server_description'};
//$album = $parsed_json->{'icestats'}->{'source'}->{'album'};
$cacheArt = $m->get('artist');
$cacheTitle = $m->get('title');
$cacheGenre = $m->get('genre');
$cacheBitrate = $m->get('bitrate');
$cacheDescription = $m->get('description');
//$cacheAlbum = $m->get('album');
$string = file_get_contents("http://10.10.1.128:30420/status-json.xsl");
$json_a = json_decode($string, true);

if ( $title != $cacheTitle OR $cacheTitle == null) {
	$m->set('changed', true);
	$m->set('artist', $art);
	$m->set('title', $title);
	$m->set('genre', $genre);
	$m->set('bitrate', $bitrate);
	$m->set('description', $description);
	//$m->set('album', $album);
	$cacheArt = $m->get('artist');
	$cacheTitle = $m->get('title');
	$cacheGenre = $m->get('genre');
	$cacheBitrate = $m->get('bitrate');
	$cacheDescription = $m->get('description');
	//$cacheAlbum = $m->get('album');
	//$fetchArt = shell_exec('/var/www/html/node_modules/album-art/cli.js "' . $cacheArt . '" "' . $cacheTitle . '"');
	//file_put_contents("img/currentAlbum.png", fopen($fetchArt, 'r+'));
} else {
	$m->set('changed', false);
}

// Output
print ('<small>' . $cacheDescription . '</small><br>');
print ('<small>now playing @ ' . $cacheBitrate . 'k:</small><br>');
print ('&#9654; Artist: ' . $cacheArt . '<br>');
print ('&#9654; Title: ' . $cacheTitle . '<br>');
?>
