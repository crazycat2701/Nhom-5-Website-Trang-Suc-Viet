<?php

chdir(dirname(__FILE__).'/../blocklayered');

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

$magiczoomInstance = Module::getInstanceByName('magiczoom');

if($magiczoomInstance && $magiczoomInstance->active) {
    $magiczoomTool = $magiczoomInstance->loadTool();
    $magiczoomFilter = 'parseTemplate'.($magiczoomTool->type == 'standard' ? 'Standard' : 'Category');
    if($magiczoomInstance->isSmarty3) {
        //Smarty v3 template engine
        $smarty->registerFilter('output', array($magiczoomInstance, $magiczoomFilter));
    } else {
        //Smarty v2 template engine
        $smarty->register_outputfilter(array($magiczoomInstance, $magiczoomFilter));
    }
    if(!isset($GLOBALS['magictoolbox']['filters'])) {
        $GLOBALS['magictoolbox']['filters'] = array();
    }
    $GLOBALS['magictoolbox']['filters']['magiczoom'] = $magiczoomFilter;
}

include(dirname(__FILE__).'/../blocklayered/blocklayered.php');

Context::getContext()->controller->php_self = 'category';
$blockLayered = new BlockLayered();
echo $blockLayered->ajaxCall();
