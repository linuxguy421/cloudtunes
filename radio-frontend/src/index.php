<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>Default Radio Station [Demo]</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<style>
body {
  background-color: #0a0a0a;
}
</style>
<link href="dist/skin/plyr/plyr.css" rel="stylesheet" type="text/css" />
<script type="text/javascript" src="lib/jquery.min.js"></script>
<script type="text/javascript">
$(document).ready(function(){
setInterval(function(){
//$("#jp-nowplaying").load('streaminfo.php'),
$("#plyr-box").load(location.href + " #plyr-box");
}, 15000);
});
</script>
</head>
<body>
<div id="plyr_container_1" class="plyr-audio">
<audio id="player" controls>
  <source src="http://192.168.39.22:30420/default0.ogg" type="audio/ogg" />
</audio>
</div>
<div id="plyr_container_1" class="plyr-audio">
	<div id="plyr-box" class="plyr-box" style="background-image:url('<?php require_once "albumart.php"; ?>'); background-repeat: no-repeat; background-position: center bottom;">
		<div id="plyr-nowplaying" class="plyr-title"><?php require_once "streaminfo.php"; ?></div>
	</div>
</div>
</body>
</html>
