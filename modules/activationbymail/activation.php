<?php

/* SSL Management */
$useSSL = true;

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../header.php');
include(dirname(__FILE__).'/activationbymail.php');

$activationbymail=new activationbymail();
$activationbymail->execActivation();

include_once(dirname(__FILE__).'/../../footer.php');

?>
