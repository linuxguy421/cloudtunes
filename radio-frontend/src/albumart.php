<?php
$m = new Memcached();
$m->addServer('10.10.1.128', 31211) or die ("Cannot connect to memcached!");
$cacheChanged = $m->get('changed');
if ( $cacheChanged != false OR $cacheChanged == null) {
    require_once "lastfm.php";
    $cacheArt = $m->get('artist');
    $cacheTitle = $m->get('title');
    echo LastFMArtwork::getArtwork($cacheArt, $cacheTitle, true, "mega");
}
?>
