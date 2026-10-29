<?php
declare(strict_types=1);
// The page moved to /statistiques.php: old links and bookmarks keep working, with their filters.
$query=$_SERVER['QUERY_STRING']??'';
header('Location: /statistiques.php'.($query!==''?'?'.$query:''),true,301);
