<?php
/** Operations > Health and backups: what is connected and what is missing, and the weekly copies of the data. Included by view_settings.php. */
?>
<div id="health"><?php function_exists('pm_view_setup_health') && pm_view_setup_health(); // what is connected and what to add ?></div>
<div id="backups"><?php function_exists('pm_view_archives') && pm_view_archives(); // weekly backups, download and restore ?></div>
