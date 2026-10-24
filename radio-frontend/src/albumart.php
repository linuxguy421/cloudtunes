<?php
$m = new Memcached();
$memcachedHost = getenv('MEMCACHED_HOST') ?: 'memcached-srv';
$memcachedPort = (int) (getenv('MEMCACHED_PORT') ?: 11211);
$m->addServer($memcachedHost, $memcachedPort) or die ("Cannot connect to memcached!");
$cacheChanged = $m->get('changed');
if ( $cacheChanged != false OR $cacheChanged == null) {
    require_once "lastfm.php";
    $cacheArt = $m->get('artist');
    $cacheTitle = $m->get('title');
    echo LastFMArtwork::getArtwork($cacheArt, $cacheTitle, true, "mega");
}
?>
