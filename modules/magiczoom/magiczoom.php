<?php

if(!defined('_PS_VERSION_')) exit;

if(!isset($GLOBALS['magictoolbox'])) {
    $GLOBALS['magictoolbox'] = array();
    $GLOBALS['magictoolbox']['filters'] = array();
    $GLOBALS['magictoolbox']['isProductScriptIncluded'] = false;
    $GLOBALS['magictoolbox']['standardTool'] = '';
    $GLOBALS['magictoolbox']['selectorImageType'] = '';
}

if(!isset($GLOBALS['magictoolbox']['magiczoom'])) {
    $GLOBALS['magictoolbox']['magiczoom'] = array();
    $GLOBALS['magictoolbox']['magiczoom']['headers'] = false;
}

class MagicZoom extends Module {

    //Prestahop v1.5 or above
    public $isPrestahop15x = false;

    //Prestahop v1.6 or above
    public $isPrestahop16x = false;

    //Smarty v3 template engine
    public $isSmarty3 = false;

    //Smarty 'getTemplateVars' function name
    public $getTemplateVars = 'getTemplateVars';

    //Suffix was added to default images types since version 1.5.1.0
    public $imageTypeSuffix = '';

    public function __construct() {

        $this->name = 'magiczoom';
        $this->tab = 'Tools';
        $this->version = '5.5.17';
        $this->author = 'Magic Toolbox';


        $this->module_key = '75112ed501254e4d263967f408833b67';

        parent::__construct();

        $this->displayName = 'Magic Zoom';
        $this->description = "Zoom into images when you hover over an image. A beautiful effect.&nbsp;<a target='_blank' title='Watch tutorial' style='color: #268CCD; text-decoration: underline; font-weight: bold;' href='http://www.youtube.com/watch?v=yAix6lXqyAw'>Watch tutorial</a>.";

        $this->confirmUninstall = 'All magiczoom settings would be deleted. Do you really want to uninstall this module ?';

        $this->isPrestahop15x = version_compare(_PS_VERSION_, '1.5', '>=');
        $this->isPrestahop16x = version_compare(_PS_VERSION_, '1.6', '>=');

        $this->isSmarty3 = $this->isPrestahop15x || Configuration::get('PS_FORCE_SMARTY_2') === "0";
        if($this->isSmarty3) {
            //Smarty v3 template engine
            $this->getTemplateVars = 'getTemplateVars';
        } else {
            //Smarty v2 template engine
            $this->getTemplateVars = 'get_template_vars';
        }

        $this->imageTypeSuffix = version_compare(_PS_VERSION_, '1.5.1.0', '>=') ? '_default' : '';

    }

    public function install() {
        $headerHookID = $this->isPrestahop15x ? Hook::getIdByName('displayHeader') : Hook::get('header');
        if(   !parent::install()
           OR !$this->registerHook($this->isPrestahop15x ? 'displayHeader' : 'header')
           OR !$this->registerHook($this->isPrestahop15x ? 'displayFooterProduct' : 'productFooter')
           OR !$this->registerHook($this->isPrestahop15x ? 'displayFooter' : 'footer')
           OR !$this->installDB()
           OR !$this->fixCSS()
           OR !$this->updatePosition($headerHookID, 0, 1)
          )
          return false;

        $this->sendStat('install');

        return true;
    }

    private function installDB() {
        if(!Db::getInstance()->Execute('CREATE TABLE `'._DB_PREFIX_.'magiczoom_settings` (
                                        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        `block` VARCHAR(32) NOT NULL,
                                        `name` VARCHAR(32) NOT NULL,
                                        `value` TEXT,
                                        `enabled` INT(2) UNSIGNED NOT NULL,
                                        PRIMARY KEY (`id`)
                                        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;'
                                      )
            OR !$this->fillDB()
            OR !$this->fixDefaultValues()
          ) return false;

        return true;
    }

    private function fixCSS() {

        //fix url's in css files
        $fileContents = file_get_contents(dirname(__FILE__).'/magiczoom.css');
        $toolPath = _MODULE_DIR_.'magiczoom';
        $pattern = '/url\(\s*(?:\'|")?(?!'.preg_quote($toolPath, '/').')\/?([^\)\s]+?)(?:\'|")?\s*\)/is';
        $replace = 'url('.$toolPath.'/$1)';
        $fixedFileContents = preg_replace($pattern, $replace, $fileContents);
        if($fixedFileContents != $fileContents) {
            //file_put_contents(dirname(__FILE__).'/magiczoom.css', $fixedFileContents);
            $fp = fopen(dirname(__FILE__).'/magiczoom.css', 'w+');
            if($fp) {
                fwrite($fp, $fixedFileContents);
                fclose($fp);
            }
        }

        return true;
    }

    private function sendStat($action = '') {

        //NOTE: don't send from working copy
        if('working' == 'v5.5.17' || 'working' == 'v4.5.29') {
            return;
        }

        $hostname = 'www.magictoolbox.com';
        $url = $_SERVER['HTTP_HOST'].preg_replace('/\/$/i', '', __PS_BASE_URI__);
        $url = urlencode(urldecode($url));
        $platformVersion = defined('_PS_VERSION_') ? _PS_VERSION_ : '';
        $path = "api/stat/?action={$action}&tool_name=magiczoom&license=trial&tool_version=v4.5.29&module_version=v5.5.17&platform_name=prestashop&platform_version={$platformVersion}&url={$url}";
        $handle = @fsockopen($hostname, 80, $errno, $errstr, 30);
        if($handle) {
            $headers  = "GET /{$path} HTTP/1.1\r\n";
            $headers .= "Host: {$hostname}\r\n";
            $headers .= "Connection: Close\r\n\r\n";
            fwrite($handle, $headers);
            fclose($handle);
        }

    }

    public function fixDefaultValues() {
        $result = true;
        if(version_compare(_PS_VERSION_, '1.5.1.0', '>=')) {
            $sql = 'UPDATE `'._DB_PREFIX_.'magiczoom_settings` SET `value`=CONCAT(value, \'_default\') WHERE `name`=\'thumb-image\' OR `name`=\'selector-image\' OR `name`=\'large-image\'';
            $result = Db::getInstance()->Execute($sql);
        }
        if($this->isPrestahop16x) {
            $sql = 'UPDATE `'._DB_PREFIX_.'magiczoom_settings` SET `value`=\'small_default\', `enabled`=1 WHERE `name`=\'thumb-image\' AND (`block`=\'blocknewproducts\' OR `block`=\'blockbestsellers\')';
            $result = Db::getInstance()->Execute($sql);
        }
        return $result;
    }

    public function uninstall() {
        //NOTE: spike to clear cache for 'homefeatured.tpl'
        if(version_compare(_PS_VERSION_, '1.5.5.0', '>=')) {
            $this->name = 'homefeatured';
            $this->_clearCache('homefeatured.tpl');
            $this->name = 'magiczoom';
        }
        if(!parent::uninstall() OR !$this->uninstallDB()) return false;
        $this->sendStat('uninstall');
        return true;
    }

    private function uninstallDB() {
        return  Db::getInstance()->Execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'magiczoom_settings`;')
                ;
    }

    public function disable($forceAll = false) {
        //NOTE: spike to clear cache for 'homefeatured.tpl'
        if(version_compare(_PS_VERSION_, '1.5.5.0', '>=')) {
            $this->name = 'homefeatured';
            $this->_clearCache('homefeatured.tpl');
            $this->name = 'magiczoom';
        }
        return parent::disable($forceAll);
    }

    public function enable($forceAll = false) {
        //NOTE: spike to clear cache for 'homefeatured.tpl'
        if(version_compare(_PS_VERSION_, '1.5.5.0', '>=')) {
            $this->name = 'homefeatured';
            $this->_clearCache('homefeatured.tpl');
            $this->name = 'magiczoom';
        }
        return parent::enable($forceAll);
    }

    public function getImagesTypes() {
        if(!isset($GLOBALS['magictoolbox']['imagesTypes'])) {
            $GLOBALS['magictoolbox']['imagesTypes'] = array('original');
            // get image type values
            $sql = 'SELECT name FROM `'._DB_PREFIX_.'image_type` ORDER BY `id_image_type` ASC';
            $result = Db::getInstance()->ExecuteS($sql);
            foreach($result as $row) {
                $GLOBALS['magictoolbox']['imagesTypes'][] = $row['name'];
            }
        }
        return $GLOBALS['magictoolbox']['imagesTypes'];
    }

    public function getContent() {

        $tool = $this->loadTool();
        $paramsMap = $this->getParamsMap();

        $_imagesTypes = array(
            'selector',
            'large',
            'thumb'
        );

        foreach($_imagesTypes as $name) {
            foreach($this->getBlocks() as $blockId => $blockLabel) {
                if($tool->params->paramExists($name.'-image', $blockId)) {
                    $tool->params->setValues($name.'-image', $this->getImagesTypes(), $blockId);
                }
            }
        }

        $magicSubmit = Tools::getValue('magic_submit', '');
        if(!empty($magicSubmit)) {
            // save settings
            if($magicSubmit == $this->l('Save settings')) {
                foreach($paramsMap as $blockId => $groups) {
                    foreach($groups as $group) {
                        foreach($group as $param) {
                            if(Tools::getValue($blockId.'-'.$param, null) !== null) {
                                $valueToSave = $value = trim(Tools::getValue($blockId.'-'.$param, ''));
                                //switch($tool->params->params[$param]['type']) {
                                switch($tool->params->getType($param)) {
                                    case "num":
                                        $valueToSave = $value = intval($value);
                                        break;
                                    case "array":
                                        if(!in_array($value, $tool->params->getValues($param))) $valueToSave = $value = $tool->params->getDefaultValue($param);
                                        break;
                                    case "text":
                                        $valueToSave = pSQL($value);
                                        break;
                                }
                                Db::getInstance()->Execute(
                                    'UPDATE `'._DB_PREFIX_.'magiczoom_settings` SET `value`=\''.$valueToSave.'\', `enabled`=1 WHERE `block`=\''.$blockId.'\' AND `name`=\''.$param.'\''
                                );
                                $tool->params->setValue($param, $value, $blockId);
                            } else {
                                Db::getInstance()->Execute(
                                    'UPDATE `'._DB_PREFIX_.'magiczoom_settings` SET `enabled`=0 WHERE `block`=\''.$blockId.'\' AND `name`=\''.$param.'\''
                                );
                                if($tool->params->paramExists($param, $blockId)) {
                                    $tool->params->removeParam($param, $blockId);
                                };
                            }
                        }
                    }
                }
                //NOTE: spike to clear cache for 'homefeatured.tpl'
                if(version_compare(_PS_VERSION_, '1.5.5.0', '>=')) {
                    $this->name = 'homefeatured';
                    $this->_clearCache('homefeatured.tpl');
                    $this->name = 'magiczoom';
                }
            }
        }

        //change subtype for some params to display them like radio
        foreach($tool->params->getParams() as $id => $param) {
            if($tool->params->getSubType($id) == 'select' && count($tool->params->getValues($id)) < 6)
                $tool->params->setSubType($id, 'radio');
        }

        // display params
        ob_start();
        include(dirname(__FILE__).'/magiczoom.settings.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();

        return $html;
    }

    public function loadTool($profile = false, $force = false) {
        if(!isset($GLOBALS['magictoolbox']['magiczoom']['class']) || $force) {
            require_once(dirname(__FILE__).'/magiczoom.module.core.class.php');
            $GLOBALS['magictoolbox']['magiczoom']['class'] = new MagicZoomModuleCoreClass();
            $tool = &$GLOBALS['magictoolbox']['magiczoom']['class'];
            // load current params
            $sql = 'SELECT `name`, `value`, `block` FROM `'._DB_PREFIX_.'magiczoom_settings` WHERE `enabled`=1';
            $result = Db::getInstance()->ExecuteS($sql);
            foreach($result as $row) {
                $tool->params->setValue($row['name'], $row['value'], $row['block']);
            }
            // load translates
            $GLOBALS['magictoolbox']['magiczoom']['translates'] = $this->getMessages();
            foreach($this->getBlocks() as $block => $label) {
                if($GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['message']['title'] != $GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['message']['translate']) {
                    $tool->params->setValue('message', $GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['message']['translate'], $block);
                }
                if($GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['loading-msg']['title'] != $GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['loading-msg']['translate']) {
                    $tool->params->setValue('loading-msg', $GLOBALS['magictoolbox']['magiczoom']['translates'][$block]['loading-msg']['translate'], $block);
                }
                // prepare image types
                foreach(array('large', 'selector', 'thumb') as $name) {
                    if($tool->params->checkValue($name.'-image', 'original', $block)) {
                        $tool->params->setValue($name.'-image', false, $block);
                    }
                }
            }

            if($tool->type == 'standard' && $tool->params->checkValue('magicscroll', 'yes', 'product')) {
                require_once(dirname(__FILE__).'/magicscroll.module.core.class.php');
                $GLOBALS['magictoolbox']['magiczoom']['magicscroll'] = new MagicScrollModuleCoreClass();
                $scroll = &$GLOBALS['magictoolbox']['magiczoom']['magicscroll'];
                $scroll->params->setScope('MagicScroll');
                $scroll->params->appendParams($tool->params->getParams('product'));//!!!!!!!!!!!!!
                $scroll->params->setValue('direction', $scroll->params->checkValue('template', array('left', 'right')) ? 'bottom' : 'right');
            }

        }

        $tool = &$GLOBALS['magictoolbox']['magiczoom']['class'];

        if($profile) {
            $tool->params->setProfile($profile);
        }

        return $tool;

    }

    public function hookHeader($params) {
        global $smarty;

        if(!$this->isPrestahop15x) {
            ob_start();
        }

        $headers = '';
        $tool = $this->loadTool();
        $tool->params->resetProfile();

        $page = $smarty->{$this->getTemplateVars}('page_name');
        switch($page) {
            case 'product':
            case 'index':
            case 'category':
            case 'manufacturer':
            case 'search':
                break;
            case 'best-sales':
                $page = 'bestsellerspage';
                break;
            case 'new-products':
                $page = 'newproductpage';
                break;
            case 'prices-drop':
                $page = 'specialspage';
                break;
            default:
                $page = '';
        }
        //old check if(preg_match('/\/prices-drop.php$/is', $GLOBALS['_SERVER']['SCRIPT_NAME']))

        if($tool->params->checkValue('include-headers-on-all-pages', 'Yes', 'default') && ($GLOBALS['magictoolbox']['magiczoom']['headers'] = true)
           || $tool->params->profileExists($page) && !$tool->params->checkValue('enable-effect', 'No', $page)
           || $page == 'index' && !$tool->params->checkValue('enable-effect', 'No', 'homefeatured') && parent::isInstalled('homefeatured') && parent::getInstanceByName('homefeatured')->active
           || $page == 'index' && !$tool->params->checkValue('enable-effect', 'No', 'blocknewproducts_home') && parent::isInstalled('blocknewproducts') && parent::getInstanceByName('blocknewproducts')->active
           || $page == 'index' && !$tool->params->checkValue('enable-effect', 'No', 'blockbestsellers_home') && parent::isInstalled('blockbestsellers') && parent::getInstanceByName('blockbestsellers')->active
           || !$tool->params->checkValue('enable-effect', 'No', 'blockviewed') && parent::isInstalled('blockviewed') && parent::getInstanceByName('blockviewed')->active
           || !$tool->params->checkValue('enable-effect', 'No', 'blockspecials') && parent::isInstalled('blockspecials') && parent::getInstanceByName('blockspecials')->active
           || (!$tool->params->checkValue('enable-effect', 'No', 'blocknewproducts') || ($page == 'index' && !$tool->params->checkValue('enable-effect', 'No', 'blocknewproducts_home'))) && parent::isInstalled('blocknewproducts') && parent::getInstanceByName('blocknewproducts')->active
           || (!$tool->params->checkValue('enable-effect', 'No', 'blockbestsellers') || ($page == 'index' && !$tool->params->checkValue('enable-effect', 'No', 'blockbestsellers_home'))) && parent::isInstalled('blockbestsellers') && parent::getInstanceByName('blockbestsellers')->active
          ) {
            // include headers
            $headers = $tool->getHeadersTemplate(_MODULE_DIR_.'magiczoom');
            $headers .= '<script type="text/javascript" src="'._MODULE_DIR_.'magiczoom/common.js"></script>';
            if($tool->type == 'standard' && $tool->params->checkValue('magicscroll', 'Yes', $page)) {
                $scroll = &$GLOBALS['magictoolbox']['magiczoom']['magicscroll'];
                $headers .= $scroll->getHeadersTemplate(_MODULE_DIR_.'magiczoom');
            }
            if($page == 'product' && !$tool->params->checkValue('enable-effect', 'No', 'product')) {
                $headers .= '
<script type="text/javascript">
    var mEvent = \''.strtolower($tool->params->getValue('selectors-change', 'product')).'\';
    var selectorsMouseoverDelay = '.strtolower($tool->params->getValue('selectors-mouseover-delay', 'product')).';
    var thumbnailLayout = \''.strtolower($tool->params->getValue('template', 'product')).'\';
    var scrollThumbnails = '.($tool->params->checkValue('magicscroll', 'Yes', 'product')?'true':'false').';
    var scrollItems = '.$tool->params->getValue('items', 'product').';
    var selectorsMargin = '.$tool->params->getValue('selectors-margin', 'product').';
    var isPrestahop15x = '.($this->isPrestahop15x ? 'true' : 'false').';
    var isPrestahop1541 = '.(version_compare(_PS_VERSION_, '1.5.4.1', '>=') ? 'true' : 'false').';
    var isPrestahop16x = '.($this->isPrestahop16x ? 'true' : 'false').';
    var mEvent = \''.strtolower($tool->params->getValue('selectors-change', 'product')).'\';
</script>';
                if(!$GLOBALS['magictoolbox']['isProductScriptIncluded']) {
                    $headers .= '<script type="text/javascript" src="'._MODULE_DIR_.'magiczoom/product.js"></script>';
                    $GLOBALS['magictoolbox']['isProductScriptIncluded'] = true;
                }
                //<style type="text/css"></style>';
            }
            /*
                Commented as discussion in issue #0021547
            */
            /*
            $headers .= '
            <!--[if !(IE 8)]>
            <style type="text/css">
                #center_column, #left_column, #right_column {overflow: hidden !important;}
            </style>
            <![endif]-->
            ';*/

            if($this->isSmarty3) {
                //Smarty v3 template engine
                if(isset($GLOBALS['magictoolbox']['filters']['magic360'])) {
                    $smarty->unregisterFilter('output', array(Module::getInstanceByName('magic360'), 'parseTemplateCategory'));
                }
                if(isset($GLOBALS['magictoolbox']['filters']['magic360flash'])) {
                    $smarty->unregisterFilter('output', array(Module::getInstanceByName('magic360flash'), 'parseTemplateCategory'));
                }
                $smarty->registerFilter('output', array(Module::getInstanceByName('magiczoom'), 'parseTemplateStandard'));
                if(isset($GLOBALS['magictoolbox']['filters']['magic360'])) {
                    $smarty->registerFilter('output', array(Module::getInstanceByName('magic360'), 'parseTemplateCategory'));
                }
                if(isset($GLOBALS['magictoolbox']['filters']['magic360flash'])) {
                    $smarty->registerFilter('output', array(Module::getInstanceByName('magic360flash'), 'parseTemplateCategory'));
                }
            } else {
                //Smarty v2 template engine
                if(isset($GLOBALS['magictoolbox']['filters']['magic360'])) {
                    $smarty->unregister_outputfilter(array(Module::getInstanceByName('magic360'), 'parseTemplateCategory'));
                }
                if(isset($GLOBALS['magictoolbox']['filters']['magic360flash'])) {
                    $smarty->unregister_outputfilter(array(Module::getInstanceByName('magic360flash'), 'parseTemplateCategory'));
                }
                $smarty->register_outputfilter(array(Module::getInstanceByName('magiczoom'), 'parseTemplateStandard'));
                if(isset($GLOBALS['magictoolbox']['filters']['magic360'])) {
                    $smarty->register_outputfilter(array(Module::getInstanceByName('magic360'), 'parseTemplateCategory'));
                }
                if(isset($GLOBALS['magictoolbox']['filters']['magic360flash'])) {
                    $smarty->register_outputfilter(array(Module::getInstanceByName('magic360flash'), 'parseTemplateCategory'));
                }
            }
            $GLOBALS['magictoolbox']['filters']['magiczoom'] = 'parseTemplateStandard';

            // presta create new class every time when hook called
            // so we need save our data in the GLOBALS
            $GLOBALS['magictoolbox']['magiczoom']['cookie'] = $params['cookie'];
            $GLOBALS['magictoolbox']['magiczoom']['productsViewed'] = (isset($params['cookie']->viewed) AND !empty($params['cookie']->viewed)) ? explode(',', $params['cookie']->viewed) : array();

            $headers = '<!-- MAGICZOOM HEADERS START -->'.$headers.'<!-- MAGICZOOM HEADERS END -->';

        }

        return $headers;

    }

    public function hookProductFooter($params) {
        //we need save this data in the GLOBALS for compatible with some Prestashop module which reset the $product smarty variable
        $GLOBALS['magictoolbox']['magiczoom']['product'] = array('id' => $params['product']->id, 'name' => $params['product']->name, 'link_rewrite' => $params['product']->link_rewrite);
        return '';
    }

    public function hookFooter($params) {

        if(!$this->isPrestahop15x) {

            $contents = ob_get_contents();
            ob_end_clean();


            if($GLOBALS['magictoolbox']['magiczoom']['headers'] == false) {
                $contents = preg_replace('/<\!-- MAGICZOOM HEADERS START -->.*?<\!-- MAGICZOOM HEADERS END -->/is', '', $contents);
            } else {
                $contents = preg_replace('/<\!-- MAGICZOOM HEADERS (START|END) -->/is', '', $contents);
            }

            echo $contents;

        }

        return '';

    }


    private static $outputMatches = array();

    public function prepareOutput($output, $index = 'DEFAULT') {

        if(!isset(self::$outputMatches[$index])) {
            preg_match_all('/<div [^>]*?class="[^"]*?MagicToolboxContainer[^"]*?".*?<\/div>\s/is', $output, self::$outputMatches[$index]);
            foreach(self::$outputMatches[$index][0] as $key => $match) {
                $output = str_replace($match, 'MAGICZOOM_MATCH_'.$index.'_'.$key.'_', $output);
            }
        } else {
            foreach(self::$outputMatches[$index][0] as $key => $match) {
                $output = str_replace('MAGICZOOM_MATCH_'.$index.'_'.$key.'_', $match, $output);
            }
            unset(self::$outputMatches[$index]);
        }
        return $output;

    }

    public function parseTemplateStandard($output, $smarty) {
        if($this->isSmarty3) {
            //Smarty v3 template engine
            //$currentTemplate = substr(basename($smarty->_current_file), 0, -4);
            $currentTemplate = substr(basename($smarty->template_resource), 0, -4);
            if($currentTemplate == 'breadcrumb') {
                $currentTemplate = 'product';
            } elseif($currentTemplate == 'pagination') {
                $currentTemplate = 'category';
            }
        } else {
            //Smarty v2 template engine
            $currentTemplate = $smarty->currentTemplate;
        }

        if($this->isPrestahop15x && $currentTemplate == 'layout') {
            if(version_compare(_PS_VERSION_, '1.5.5.0', '>=')) {
                //NOTE: because we do not know whether the effect is applied to the blocks in the cache
                $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
            }
            //NOTE: full contents in prestashop 1.5.x
            if($GLOBALS['magictoolbox']['magiczoom']['headers'] == false) {
                $output = preg_replace('/<\!-- MAGICZOOM HEADERS START -->.*?<\!-- MAGICZOOM HEADERS END -->/is', '', $output);
            } else {
                $output = preg_replace('/<\!-- MAGICZOOM HEADERS (START|END) -->/is', '', $output);
            }
            return $output;
        }

        switch($currentTemplate) {
            case 'search':
            case 'manufacturer':
                //$currentTemplate = 'manufacturer';
                break;
            case 'best-sales':
                $currentTemplate = 'bestsellerspage';
                break;
            case 'new-products':
                $currentTemplate = 'newproductpage';
                break;
            case 'prices-drop':
                $currentTemplate = 'specialspage';
                break;
            case 'blockbestsellers-home':
                $currentTemplate = 'blockbestsellers_home';
                break;
            case 'product-list'://for 'Layered navigation block'
                if(strpos($_SERVER['REQUEST_URI'], 'blocklayered-ajax.php') !== false) {
                    $currentTemplate = 'category';
                }
                break;
        }

        $tool = $this->loadTool();
        if(!$tool->params->profileExists($currentTemplate) || $tool->params->checkValue('enable-effect', 'No', $currentTemplate)) {
            return $output;
        }
        $tool->params->setProfile($currentTemplate);

        global $link;
        $cookie = &$GLOBALS['magictoolbox']['magiczoom']['cookie'];
        if(method_exists($link, 'getImageLink')) {
            $_link = &$link;
        } else {
            //for Prestashop ver 1.1
            $_link = &$this;
        }

        $output = self::prepareOutput($output);

        switch($currentTemplate) {
            case 'homefeatured':
                $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;

                $categoryID = $this->isPrestahop15x ? Context::getContext()->shop->getCategory() : 1;
                $category = new Category($categoryID);
                $nb = intval(Configuration::get('HOME_FEATURED_NBR'));//Number of product displayed
                $products = $category->getProducts(intval($cookie->id_lang), 1, ($nb ? $nb : 10));

                if(is_array($products))
                foreach($products as $product) {
                    $lrw = $product['link_rewrite'];
                    if(!$tool->params->checkValue('link-to-product-page', 'No')) {
                        $lnk = $link->getProductLink($product['id_product'], $lrw, isset($product['category']) ? $product['category'] : null);
                    } else {
                        $lnk = false;
                    }
                    $thumb = $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('thumb-image'));
                    $image = $tool->getMainTemplate(array(
                        'id' => 'homefeatured'.$product['id_image'],
                        'group' => 'homefeatured',
                        'link' => $lnk,
                        'img' => $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('large-image')),
                        'thumb' => $thumb,
                        'title' => $product['name'],
                        'shortDescription' => $product['description_short'],
                        'description' => $product['description']
                    ));
                    //need a.product_image > img for blockcart module
                    $image = '<div class="MagicToolboxContainer"><div style="width:0px;height:1px;overflow:hidden;visibility:hidden;"><a class="product_image" href="#"><img src="'.$thumb.'" /></a></div>'.$image.'</div>';
                    //$image = '<div class="MagicToolboxContainer">'.$image.'</div>';
                    $image_pattern = '<img[^>]*?src="[^"]*?'.preg_quote($_link->getImageLink($lrw, $product['id_image'], 'home'.$this->imageTypeSuffix), '/').'"[^>]*>';
                    $pattern = $image_pattern.'[^<]*(<span[^>]*?class="new"[^>]*>[^<]*<\/span>)?';
                    $pattern = '<a[^>]*?href="[^"]*?"[^>]*>[^<]*'.$pattern.'[^<]*<\/a>|'.$image_pattern;
                    $output = preg_replace('/'.$pattern.'/is', $image, $output);
                }
                break;
            case 'category':
            case 'manufacturer':
            case 'newproductpage':
            case 'bestsellerspage':
            case 'specialspage':
            case 'search':
                //global $p, $n, $orderBy, $orderWay;
                //$category = new Category(intval(Tools::getValue('id_category')), intval($cookie->id_lang));
                //$products = $category->getProducts(intval($cookie->id_lang), intval($p), intval($n), $orderBy, $orderWay);
                $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
                $products = $smarty->{$this->getTemplateVars}('products');
                if(is_array($products))
                foreach($products as $product) {
                    $lrw = $product['link_rewrite'];
                    if(!$tool->params->checkValue('link-to-product-page', 'No')) {
                        $lnk = $link->getProductLink($product['id_product'], $lrw, isset($product['category']) ? $product['category'] : null);
                    } else {
                        $lnk = false;
                    }
                    $thumb = $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('thumb-image'));
                    $image = $tool->getMainTemplate(array(
                        'id' => 'category'.$product['id_image'],
                        'group' => 'category',
                        'link' => $lnk,
                        'img' => $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('large-image')),
                        'thumb' => $thumb,
                        'title' => $product['name'],
                        'shortDescription' => $product['description_short'],
                        'description' => isset($product['description']) ? $product['description'] : $this->getProductDescription($product['id_product'], $cookie->id_lang)
                    ));
                    //$image = preg_replace('/<a class="MagicZoom"/is', '<a class="MagicZoom product_img_link"', $image);
                    $image_suffix = $tool->params->getValue('thumb-image') ? '-'.$tool->params->getValue('thumb-image') : '';
                    $file_path = _PS_PROD_IMG_DIR_.$product['id_image'].$image_suffix.'.jpg';
                    if(!file_exists($file_path)) {
                        $split_ids = explode('-', $product['id_image']);
                        $id_image = (isset($split_ids[1]) ? $split_ids[1] : $split_ids[0]);
                        $folders = implode('/', str_split((string)$id_image)).'/';
                        $file_path = _PS_PROD_IMG_DIR_.$folders.$id_image.$image_suffix.'.jpg';
                    }
                    $size = getimagesize($file_path);
                    if(!$this->isPrestahop16x) {
                        //need a.product_img_link > img for blockcart module
                        $image = '<div class="MagicToolboxContainer" style="float: left; width: '.$size[0].'px; margin-right: 0.6em;" ><div style="width:0px;height:1px;overflow:hidden;visibility:hidden;"><a class="product_img_link" href="#"><img src="'.$thumb.'" /></a></div>'.$image.'</div>';
                        //$image = '<div class="MagicToolboxContainer" style="float: left; width: '.$size[0].'px; margin-right: 0.6em;" >'.$image.'</div>';
                    }
                    $image_pattern = '<img[^>]*?src="[^"]*?'.preg_quote($_link->getImageLink($lrw, $product['id_image'], 'home'.$this->imageTypeSuffix), '/').'"[^>]*>';
                    $pattern = $image_pattern.'[^<]*(<span[^>]*?class="new"[^>]*>[^<]*<\/span>)?';
                    $pattern = '<a[^>]*?href="[^"]*?"[^>]*>[^<]*'.$pattern.'[^<]*<\/a>|'.$image_pattern;
                    //preg_match('/'.$pattern.'/is', $output, $_m);
                    //if(isset($_m[1])) $image = preg_replace('/<\/div>$/is', $_m[1].'</div>', $image);
                    $output = preg_replace('/'.$pattern.'/is', $image, $output);
                }
                break;
            case 'product':
                if(!isset($GLOBALS['magictoolbox']['magiczoom']['product'])) {
                    //for skip loyalty module product.tpl
                    //return self::prepareOutput($output);
                    break;
                }
                //$product = new Product(intval($smarty->$tpl_vars['product']->id), true, intval($cookie->id_lang));
                //get some data from $GLOBALS for compatible with Prestashop modules which reset the $product smarty variable
                $product = new Product(intval($GLOBALS['magictoolbox']['magiczoom']['product']['id']), true, intval($cookie->id_lang));
                $lrw = $product->link_rewrite;
                $pid = intval($product->id);

                $productImages = $product->getImages(intval($cookie->id_lang));
                if(!is_array($productImages) || empty($productImages)) {
                    break;
                }

                $cover = $smarty->{$this->getTemplateVars}('cover');
                if(!isset($cover['id_image'])) {
                    break;
                }
                $coverImageIds = is_numeric($cover['id_image']) ? $pid.'-'.$cover['id_image'] : $cover['id_image'];

                $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
                $thumb = $_link->getImageLink($lrw, $coverImageIds, $tool->params->getValue('thumb-image'));
                $image = $tool->getMainTemplate(array(
                    'id' => 'MainImage',
                    'img' => $_link->getImageLink($lrw, $coverImageIds, $tool->params->getValue('large-image')),
                    'thumb' => $thumb,
                    'title' => $product->name,
                    'alt' => $cover['legend'],
                    'shortDescription' => $product->description_short,
                    'description' => $product->description
                ));

                $iTypes = $this->getImagesTypes();
                $selectors = array();
                if(count($productImages))
                foreach($productImages as $i) {
                    $s = $tool->getSelectorTemplate(array(
                        'id' => 'MainImage',
                        'img' => $_link->getImageLink($lrw, $pid.'-'.$i['id_image'], $tool->params->getValue('large-image')),
                        'medium' => $_link->getImageLink($lrw, $pid.'-'.$i['id_image'], $tool->params->getValue('thumb-image')),
                        'thumb' => $_link->getImageLink($lrw, $pid.'-'.$i['id_image'], $tool->params->getValue('selector-image')),
                        'title' => $i['legend'],
                        'alt' => $i['legend']
                    ));
                    $s = str_replace('<img ', '<img id="thumb_'.$i['id_image'].'" ', $s);
                    //NOTE: onclick for prevent click on selector before it is initialized
                    $s = str_replace('<a ', '<a class="magicthickbox" onclick="return false;" ', $s);

                    $pattern = preg_quote($_link->getImageLink($lrw, $pid.'-'.$i['id_image'], 'medium'.$this->imageTypeSuffix), '/');
                    $pattern = '<img\b[^>]*?\bsrc="[^"]*?'.$pattern.'"[^>]*+>';
                    $pattern = '(?:<img\b[^>]*?\bid="thumb_'.$i['id_image'].'"[^>]*+>|'.$pattern.')';
                    $pattern = '<a\b[^>]*+>[^<]*+'.$pattern.'[^<]*+<\/a>|'.$pattern;
                    if(!$tool->params->checkValue('template', 'original')) {
                        $selectors[] = $s;
                        $s = '';
                    }
                    //NOTE: append selector in their preserved place or remove them from contents
                    $output = preg_replace('/'.$pattern.'/is', $s, $output, 1);
                }

                //for magic360(flash) module
                if(isset($GLOBALS['magictoolbox']['magic360'])) {
                    $images = Db::getInstance()->ExecuteS('SELECT id_image FROM `'._DB_PREFIX_.'magic360_images` WHERE id_product='.$pid.' LIMIT 1');
                    if(count($images) && !$GLOBALS['magictoolbox']['magic360']['class']->params->checkValue('enable-effect', 'No', 'product')) {
                        $GLOBALS['magictoolbox']['standardTool'] = 'magiczoom';
                        $GLOBALS['magictoolbox']['selectorImageType'] = $tool->params->getValue('selector-image');

                        //$image = '<div id="mainImageContainer" style="width: 1px; height: 1px; overflow: hidden; position: absolute; ">'.$image.'</div>'.
                        //    '<div id="magic360Container"><!-- MAGIC360 --></div>';
                        //$image = '<div style="position: relative;">'.$image.'</div>';
                        $image = 
                            '<div style="position: relative;">'.
                                '<div id="mainImageContainer" style="position: absolute; left: -5000px;">'.
                                    //NOTE: we need this div because of issue with MZP, which clones the parent node
                                    '<div>'.$image.'</div>'.
                                '</div>'.
                                '<div id="magic360Container"><!-- MAGIC360 --></div>'.
                            '</div>';

                        if($tool->params->checkValue('template', 'original')) {
                            $output = preg_replace('/(<ul[^>]*?id="thumbs_list_frame"[^>]*>)/is', '$1<li id="thumbnail_9999999999"><!-- MAGIC360SELECTOR --></li>', $output);
                        } else {
                            array_unshift($selectors, '<!-- MAGIC360SELECTOR -->');
                        }
                    }
                }

                if(!$tool->params->checkValue('template', 'original')) {
                    //remove selectors from contents
                    //$output = preg_replace('/<div [^>]*?id="thumbs_list"[^>]*>.*?<\/div>/is', '', $output);
                    //NOTE: added support custom theme #53897
                    $output = preg_replace('/<div [^>]*?(?:id="thumbs_list"|class="[^"]*?image-additional[^"]*")[^>]*>.*?<\/div>/is', '', $output);

                    //NOTE: div#views_block is parent for div#thumbs_list
                    $output = preg_replace('/<div [^>]*?id="views_block"[^>]*>.*?<\/div>/is', '', $output);

                    //#resetImages link
                    //$output = preg_replace('/<\!-- thumbnails -->[^<]*<p[^>]*><a[^>]+reset[^>]+>.*?<\/a><\/p>/is', '<!-- thumbnails -->', $output);
                    //remove "View full size" link
                    $output = preg_replace('/<li>[^<]*<span[^>]*?id="view_full_size"[^>]*?>[^<]*<\/span>[^<]*<\/li>/is', '', $output);
                } else {
                    $tool->params->setValue('template', 'bottom');
                    //NOTE: make views_block visible (it is hidden when product has only one image) when magic360 icon is added
                    if($GLOBALS['magictoolbox']['standardTool'] && count($productImages) == 1) {
                        $output = preg_replace('/(<div\s[^>]*?id="views_block"[^>]*?class="[^"]*?)hidden([^"]*"[^>]*>)/is', '$1$2', $output);
                        //NOTE: pattern breaks down a bit without this p.clear
                        $output = preg_replace('/(<ul [^>]*?id="usefull_link_block"[^>]*>)/is', '<p class="clear"></p>$1', $output);
                    }
                }

                //NOTE: we need this sizes for template renderer
                $sql = 'SELECT name, width, height FROM `'._DB_PREFIX_.'image_type` WHERE name in (\''.$tool->params->getValue('thumb-image').'\', \''.$tool->params->getValue('selector-image').'\')';
                $result = Db::getInstance()->ExecuteS($sql);
                $result[$result[0]['name']] = $result[0];
                $result[$result[1]['name']] = $result[1];
                $tool->params->setValue('thumb-max-width', $result[$tool->params->getValue('thumb-image')]['width']);
                $tool->params->setValue('thumb-max-height', $result[$tool->params->getValue('thumb-image')]['height']);
                $tool->params->setValue('selector-max-width', $result[$tool->params->getValue('selector-image')]['width']);
                $tool->params->setValue('selector-max-height', $result[$tool->params->getValue('selector-image')]['height']);

                require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'magictoolbox.templatehelper.class.php');
                MagicToolboxTemplateHelperClass::setPath(dirname(__FILE__). DIRECTORY_SEPARATOR .'templates');
                MagicToolboxTemplateHelperClass::setOptions($tool->params);
                $html = MagicToolboxTemplateHelperClass::render(array(
                    'main' => $image,
                    'thumbs' => $selectors,
                    'pid' => $pid,
                ));
                //need img#bigpic for blockcart module
                $html = preg_replace('#<div\b[^>]*?class="[^"]*?\bMagicToolboxContainer\b[^"]*+"[^>]*+>#is', '$0<div style="width:0px;height:1px;overflow:hidden;visibility:hidden;"><img id="bigpic" src="'.$thumb.'" /></div>', $html);

                //NOTE: append main image
                /*
                $imagePatternTemplate = '<img [^>]*?src="[^"]*?__SRC__"[^>]*>';
                $patternTemplate = '<a [^>]*>[^<]*'.$imagePatternTemplate.'[^<]*<\/a>|'.$imagePatternTemplate;
                $patternTemplate = '<span [^>]*?id="view_full_size"[^>]*>[^<]*'.
                                   '(?:<span [^>]*?class="[^"]*"[^>]*>[^<]*<\/span>[^<]*)*'.
                                   '(?:'.$patternTemplate.')[^<]*'.
                                   '(?:<span [^>]*?class="[^"]*?span_link[^"]*"[^>]*>.*?<\/span>[^<]*)*'.
                                   '<\/span>|'.$patternTemplate;
                //NOTE: added support custom theme #53897
                $patternTemplate = $patternTemplate.'|'.
                    '<div [^>]*?id="wrap"[^>]*>[^<]*'.
                    '<a [^>]*>[^<]*'.
                    '<span [^>]*?id="view_full_size"[^>]*>[^<]*'.
                    $imagePatternTemplate.'[^<]*'.
                    '<\/span>[^<]*'.
                    '<\/a>[^<]*'.
                    '<\/div>[^<]*'.
                    '<div [^>]*?class="[^"]*?zoom-b[^"]*"[^>]*>[^<]*'.
                    '<a [^>]*>[^<]*<\/a>[^<]*'.
                    '<\/div>';
                //NOTE: added support custom theme #54204
                $patternTemplate = $patternTemplate.'|'.
                    '<span [^>]*?id="view_full_size"[^>]*>[^<]*'.
                    '<a [^>]*>[^<]*'.
                    '<img [^>]*>[^<]*'.
                    $imagePatternTemplate.'[^<]*'.
                    '<span [^>]*?class="[^"]*?mask[^"]*"[^>]*>.*?<\/span>[^<]*'.
                    '<\/a>[^<]*'.
                    '<\/span>[^<]*';
                $patternTemplate = '(?:'.$patternTemplate.')';
                //$patternTemplate = '(<div[^>]*?id="image-block"[^>]*>[^<]*)'.$patternTemplate;//NOTE: we need this to determine the main image
                //NOTE: added support custom theme #53897
                $patternTemplate = '(<div [^>]*?(?:id="image-block"|class="[^"]*?image[^"]*")[^>]*>[^<]*)'.$patternTemplate;

                $srcPattern = preg_quote($_link->getImageLink($lrw, $coverImageIds, 'large'.$this->imageTypeSuffix), '/');
                $pattern = str_replace('__SRC__', $srcPattern, $patternTemplate);

                $replaced = 0;
                $output = preg_replace('/'.$pattern.'/is', '$1'.$html, $output, -1, $replaced);
                if(!$replaced) {
                    $iTypes = $this->getImagesTypes();
                    foreach($iTypes as $iType) {
                        if($iType != 'large'.$this->imageTypeSuffix) {
                            $srcPattern = preg_quote($_link->getImageLink($lrw, $coverImageIds, $iType), '/');
                            $pattern = str_replace('__SRC__', $srcPattern, $patternTemplate);
                            $output = preg_replace('/'.$pattern.'/is', '$1'.$html, $output, -1, $replaced);
                            if($replaced) break;
                        }
                    }
                }
                */
                //NOTE: common pattern to match div#image-block tag
                $pattern =  '(<div\b[^>]*?(?:\bid\s*+=\s*+"image-block"|\bclass\s*+=\s*+"[^"]*?\bimage\b[^"]*+")[^>]*+>)'.
                            '('.
                            '(?:'.
                                '[^<]++'.
                                '|'.
                                '<(?!/?div\b|!--)'.
                                '|'.
                                '<!--.*?-->'.
                                '|'.
                                '<div\b[^>]*+>'.
                                    '(?2)'.
                                '</div\s*+>'.
                            ')*+'.
                            ')'.
                            '</div\s*+>';
                //$replaced = 0;
                //preg_match_all('%'.$pattern.'%is', $output, $__matches, PREG_SET_ORDER);
                //NOTE: limit = 1 because pattern can be matched with other products, located below the main product
                $output = preg_replace('%'.$pattern.'%is', '$1'.$html.'</div>', $output, 1/*, $replaced*/);

                break;
            case 'blockspecials':
                $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
                $product = $smarty->{$this->getTemplateVars}('special');
                $lrw = $product['link_rewrite'];
                if(!$tool->params->checkValue('link-to-product-page', 'No') && (!Tools::getValue('id_product', false) || (Tools::getValue('id_product', false) != $product['id_product']))) {
                    $lnk = $link->getProductLink($product['id_product'], $lrw, isset($product['category']) ? $product['category'] : null);
                } else {
                    $lnk = false;
                }
                $image = $tool->getMainTemplate(array(
                    'id' => 'blockspecials'.$product['id_image'],
                    'group' => 'blockspecials',
                    'link' => $lnk,
                    'img' => $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('large-image')),
                    'thumb' => $_link->getImageLink($lrw, $product['id_image'], $tool->params->getValue('thumb-image')),
                    'title' => $product['name'],
                    'shortDescription' => $product['description_short'],
                    'description' => isset($product['description']) ? $product['description'] : $this->getProductDescription($product['id_product'], $cookie->id_lang)
                ));
                $image = '<div class="MagicToolboxContainer">'.$image.'</div>';

                $pattern = '<img[^>]*?src="[^"]*?'.preg_quote($_link->getImageLink($lrw, $product['id_image'], ($this->isPrestahop16x ? 'small': 'medium').$this->imageTypeSuffix), '/').'"[^>]*>';
                $pattern = '(<a[^>]*?href="[^"]*?"[^>]*>[^<]*)?'.$pattern.'([^<]*<\/a>)?';
                $output = preg_replace('/'.$pattern.'/is', $image, $output);

                break;
            case 'blockviewed':
                $productsViewed = array_slice($GLOBALS['magictoolbox']['magiczoom']['productsViewed'], 0, Configuration::get('PRODUCTS_VIEWED_NBR'));
                foreach($productsViewed as $id_product) {
                    $productViewedObj = new Product(intval($id_product), false, intval($cookie->id_lang));
                    if(!Validate::isLoadedObject($productViewedObj) OR !$productViewedObj->active) {
                        continue;
                    }
                    $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
                    $images = $productViewedObj->getImages(intval($cookie->id_lang));
                    foreach($images as $image) {
                        if($image['cover']) {
                            $productViewedObj->cover = $productViewedObj->id.'-'.$image['id_image'];
                            $productViewedObj->legend = $image['legend'];
                            break;
                        }
                    }
                    if(!isset($productViewedObj->cover)) {
                        $productViewedObj->cover = Language::getIsoById($cookie->id_lang).'-default';
                        $productViewedObj->legend = '';
                    }
                    $lrw = $productViewedObj->link_rewrite;
                    if(!$tool->params->checkValue('link-to-product-page', 'No') && (!Tools::getValue('id_product', false) || (Tools::getValue('id_product', false) != $id_product))) {
                        $lnk = $link->getProductLink($id_product, $lrw, $productViewedObj->category);
                    } else {
                        $lnk = false;
                    }
                    $image = $tool->getMainTemplate(array(
                        'id' => 'blockviewed'.$id_product,
                        'group' => 'blockviewed',
                        'link' => $lnk,
                        'img' => $_link->getImageLink($lrw, $productViewedObj->cover, $tool->params->getValue('large-image')),
                        'thumb' => $_link->getImageLink($lrw, $productViewedObj->cover, $tool->params->getValue('thumb-image')),
                        'title' => $productViewedObj->name,
                        'shortDescription' => $productViewedObj->description_short,
                        'description' => $productViewedObj->description
                    ));
                    $image_suffix = $tool->params->getValue('thumb-image') ? '-'.$tool->params->getValue('thumb-image') : '';
                    $file_path = _PS_PROD_IMG_DIR_.$productViewedObj->cover.$image_suffix.'.jpg';
                    if(!file_exists($file_path)) {
                        $split_ids = explode('-', $productViewedObj->cover);
                        $id_image = (isset($split_ids[1]) ? $split_ids[1] : $split_ids[0]);
                        $folders = implode('/', str_split((string)$id_image)).'/';
                        $file_path = _PS_PROD_IMG_DIR_.$folders.$id_image.$image_suffix.'.jpg';
                    }
                    $size = getimagesize($file_path);
                    $image = '<div class="MagicToolboxContainer" style="float: left; width: '.$size[0].'px;">'.$image.'</div>';
                    $pattern = '<img[^>]*?src="[^"]*?'.preg_quote($_link->getImageLink($lrw, $productViewedObj->cover, ($this->isPrestahop16x ? 'small': 'medium').$this->imageTypeSuffix), '/').'"[^>]*>';
                    $pattern = '(<a[^>]*?href="[^"]*?"[^>]*>[^<]*)?'.$pattern.'([^<]*<\/a>)?';
                    $output = preg_replace('/'.$pattern.'/is', $image, $output);
                }
                break;
            case 'blockbestsellers':
            case 'blockbestsellers_home':
            case 'blocknewproducts':
            case 'blocknewproducts_home':
                if(in_array($currentTemplate, array('blockbestsellers', 'blockbestsellers_home'))) {
                    //$products = $smarty->{$this->getTemplateVars}('best_sellers');
                    //to get with description etc.
                    //$products = ProductSale::getBestSales(intval($cookie->id_lang), 0, version_compare(_PS_VERSION_, '1.5.1.0', '>=') ? 5 : 4);
                    //NOTE: blockbestsellers module uses a 'getBestSalesLight' function (the result may be different from 'getBestSales')
                    //      description we get a little further (with 'getProductDescription' function)
                    $pCount = $this->isPrestahop16x ? 8 : (version_compare(_PS_VERSION_, '1.5.1.0', '>=') ? 5 : 4);
                    $products = ProductSale::getBestSalesLight(intval($cookie->id_lang), 0, $pCount);
                } else {
                    $products = $smarty->{$this->getTemplateVars}('new_products');
                }
                if(!is_array($products)) break;
                $pCount = count($products);
                if($pCount) {
                    $GLOBALS['magictoolbox']['magiczoom']['headers'] = true;
                    for($i = 0; /*$i < 2 &&*/ $i < $pCount; $i++) {
                        $lrw = $products[$i]['link_rewrite'];
                        if(!$tool->params->checkValue('link-to-product-page', 'No') && (!Tools::getValue('id_product', false) || (Tools::getValue('id_product', false) != $products[$i]['id_product']))) {
                            $lnk = $link->getProductLink($products[$i]['id_product'], $lrw, isset($products[$i]['category']) ? $products[$i]['category'] : null);
                        } else {
                            $lnk = false;
                        }
                        $image = $tool->getMainTemplate(array(
                            'id' => $currentTemplate.$products[$i]['id_image'],
                            'group' => $currentTemplate,
                            'link' => $lnk,
                            'img' => $_link->getImageLink($lrw, $products[$i]['id_image'], $tool->params->getValue('large-image')),
                            'thumb' => $_link->getImageLink($lrw, $products[$i]['id_image'], $tool->params->getValue('thumb-image')),
                            'title' => $products[$i]['name'],
                            'shortDescription' => $products[$i]['description_short'],
                            'description' => isset($products[$i]['description']) ? $products[$i]['description'] : $this->getProductDescription($products[$i]['id_product'], $cookie->id_lang)
                        ));
                        $image = '<div class="MagicToolboxContainer">'.$image.'</div>';
                        if(in_array($currentTemplate, array('blockbestsellers_home', 'blocknewproducts_home'))) {
                            $pattern = preg_quote($_link->getImageLink($lrw, $products[$i]['id_image'], 'home'.$this->imageTypeSuffix), '/');
                        } else if($this->isPrestahop15x && $currentTemplate == 'blockbestsellers' || $this->isPrestahop16x) {
                            $pattern = preg_quote($_link->getImageLink($lrw, $products[$i]['id_image'], 'small'.$this->imageTypeSuffix), '/');
                        } else {
                            $pattern = preg_quote($_link->getImageLink($lrw, $products[$i]['id_image'], 'medium'.$this->imageTypeSuffix), '/');
                        }
                        $pattern = '<img[^>]*?src="[^"]*?'.$pattern.'"[^>]*>';
                        $pattern = '(?:<a[^>]*>[^<]*)?(?:<span class="number">.*?<\/span>[^<]*)?'.$pattern.'(?:[^<]*<\/a>)?';
                        $output = preg_replace('/'.$pattern.'/is', $image, $output);
                    }
                }
                break;
        }

        return self::prepareOutput($output);

    }


    public function getAllSpecial($id_lang, $beginning = false, $ending = false) {

        $currentDate = date('Y-m-d');
        $result = Db::getInstance()->ExecuteS('
        SELECT p.*, pl.`description`, pl.`description_short`, pl.`link_rewrite`, pl.`meta_description`, pl.`meta_keywords`, pl.`meta_title`, pl.`name`, p.`ean13`,
            i.`id_image`, il.`legend`, t.`rate`
        FROM `'._DB_PREFIX_.'product` p
        LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product` AND pl.`id_lang` = '.intval($id_lang).')
        LEFT JOIN `'._DB_PREFIX_.'image` i ON (i.`id_product` = p.`id_product` AND i.`cover` = 1)
        LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (i.`id_image` = il.`id_image` AND il.`id_lang` = '.intval($id_lang).')
        LEFT JOIN `'._DB_PREFIX_.'tax` t ON t.`id_tax` = p.`id_tax`
        WHERE (`reduction_price` > 0 OR `reduction_percent` > 0)
        '.((!$beginning AND !$ending) ?
            'AND (`reduction_from` = `reduction_to` OR (`reduction_from` <= \''.$currentDate.'\' AND `reduction_to` >= \''.$currentDate.'\'))'
        :
            ($beginning ? 'AND `reduction_from` <= \''.$beginning.'\'' : '').($ending ? 'AND `reduction_to` >= \''.$ending.'\'' : '')).'
        AND p.`active` = 1
        ORDER BY RAND()');

        if (!$result)
            return false;

        foreach ($result as $row)
            $rows[] = Product::getProductProperties($id_lang, $row);

        return $rows;
    }

    //for Prestashop ver 1.1
    public function getImageLink($name, $ids, $type = null) {
        return _THEME_PROD_DIR_.$ids.($type ? '-'.$type : '').'.jpg';
    }


    public function getProductDescription($id_product, $id_lang) {
        $sql = 'SELECT `description` FROM `'._DB_PREFIX_.'product_lang` WHERE `id_product` = '.(int)($id_product).' AND `id_lang` = '.(int)($id_lang);
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS($sql);
        return isset($result[0]['description'])? $result[0]['description'] : '';
    }

    function fillDB() {
		$sql = 'INSERT INTO `'._DB_PREFIX_.'magiczoom_settings` (`block`, `name`, `value`, `enabled`) VALUES
				(\'default\', \'thumb-image\', \'large\', 1),
				(\'default\', \'selector-image\', \'small\', 1),
				(\'default\', \'large-image\', \'thickbox\', 1),
				(\'default\', \'zoom-width\', \'300\', 1),
				(\'default\', \'zoom-height\', \'300\', 1),
				(\'default\', \'zoom-position\', \'right\', 1),
				(\'default\', \'zoom-align\', \'top\', 1),
				(\'default\', \'zoom-distance\', \'15\', 1),
				(\'default\', \'opacity\', \'50\', 1),
				(\'default\', \'opacity-reverse\', \'No\', 1),
				(\'default\', \'zoom-fade\', \'Yes\', 1),
				(\'default\', \'zoom-window-effect\', \'shadow\', 1),
				(\'default\', \'zoom-fade-in-speed\', \'200\', 1),
				(\'default\', \'zoom-fade-out-speed\', \'200\', 1),
				(\'default\', \'fps\', \'25\', 1),
				(\'default\', \'smoothing\', \'Yes\', 1),
				(\'default\', \'smoothing-speed\', \'40\', 1),
				(\'default\', \'initialize-on\', \'load\', 1),
				(\'default\', \'click-to-activate\', \'No\', 1),
				(\'default\', \'click-to-deactivate\', \'No\', 1),
				(\'default\', \'show-loading\', \'Yes\', 1),
				(\'default\', \'loading-msg\', \'Loading zoom...\', 1),
				(\'default\', \'loading-opacity\', \'75\', 1),
				(\'default\', \'loading-position-x\', \'-1\', 1),
				(\'default\', \'loading-position-y\', \'-1\', 1),
				(\'default\', \'entire-image\', \'No\', 1),
				(\'default\', \'show-title\', \'top\', 1),
				(\'default\', \'link-to-product-page\', \'Yes\', 1),
				(\'default\', \'include-headers-on-all-pages\', \'No\', 1),
				(\'default\', \'show-message\', \'Yes\', 1),
				(\'default\', \'message\', \'Move your mouse over image\', 1),
				(\'default\', \'right-click\', \'No\', 1),
				(\'default\', \'disable-zoom\', \'No\', 1),
				(\'default\', \'always-show-zoom\', \'No\', 1),
				(\'default\', \'drag-mode\', \'No\', 1),
				(\'default\', \'move-on-click\', \'Yes\', 1),
				(\'default\', \'x\', \'-1\', 1),
				(\'default\', \'y\', \'-1\', 1),
				(\'default\', \'preserve-position\', \'No\', 1),
				(\'default\', \'fit-zoom-window\', \'Yes\', 1),
				(\'default\', \'hint\', \'Yes\', 1),
				(\'default\', \'hint-text\', \'Zoom\', 1),
				(\'default\', \'hint-position\', \'top left\', 1),
				(\'default\', \'hint-opacity\', \'75\', 1),
				(\'product\', \'template\', \'original\', 0),
				(\'product\', \'magicscroll\', \'No\', 0),
				(\'product\', \'thumb-image\', \'large\', 0),
				(\'product\', \'selector-image\', \'small\', 0),
				(\'product\', \'large-image\', \'thickbox\', 0),
				(\'product\', \'zoom-width\', \'300\', 0),
				(\'product\', \'zoom-height\', \'300\', 0),
				(\'product\', \'zoom-position\', \'right\', 0),
				(\'product\', \'zoom-align\', \'top\', 0),
				(\'product\', \'zoom-distance\', \'15\', 0),
				(\'product\', \'opacity\', \'50\', 0),
				(\'product\', \'opacity-reverse\', \'No\', 0),
				(\'product\', \'zoom-fade\', \'Yes\', 0),
				(\'product\', \'zoom-window-effect\', \'shadow\', 0),
				(\'product\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'product\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'product\', \'fps\', \'25\', 0),
				(\'product\', \'smoothing\', \'Yes\', 0),
				(\'product\', \'smoothing-speed\', \'40\', 0),
				(\'product\', \'selectors-margin\', \'5\', 0),
				(\'product\', \'selectors-change\', \'click\', 0),
				(\'product\', \'selectors-class\', \'\', 0),
				(\'product\', \'preload-selectors-small\', \'Yes\', 0),
				(\'product\', \'preload-selectors-big\', \'No\', 0),
				(\'product\', \'selectors-effect\', \'fade\', 0),
				(\'product\', \'selectors-effect-speed\', \'400\', 0),
				(\'product\', \'selectors-mouseover-delay\', \'60\', 0),
				(\'product\', \'initialize-on\', \'load\', 0),
				(\'product\', \'click-to-activate\', \'No\', 0),
				(\'product\', \'click-to-deactivate\', \'No\', 0),
				(\'product\', \'show-loading\', \'Yes\', 0),
				(\'product\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'product\', \'loading-opacity\', \'75\', 0),
				(\'product\', \'loading-position-x\', \'-1\', 0),
				(\'product\', \'loading-position-y\', \'-1\', 0),
				(\'product\', \'entire-image\', \'No\', 0),
				(\'product\', \'show-title\', \'top\', 0),
				(\'product\', \'enable-effect\', \'Yes\', 1),
				(\'product\', \'show-message\', \'Yes\', 0),
				(\'product\', \'message\', \'Move your mouse over image\', 0),
				(\'product\', \'right-click\', \'No\', 0),
				(\'product\', \'disable-zoom\', \'No\', 0),
				(\'product\', \'always-show-zoom\', \'No\', 0),
				(\'product\', \'drag-mode\', \'No\', 0),
				(\'product\', \'move-on-click\', \'Yes\', 0),
				(\'product\', \'x\', \'-1\', 0),
				(\'product\', \'y\', \'-1\', 0),
				(\'product\', \'preserve-position\', \'No\', 0),
				(\'product\', \'fit-zoom-window\', \'Yes\', 0),
				(\'product\', \'hint\', \'Yes\', 0),
				(\'product\', \'hint-text\', \'Zoom\', 0),
				(\'product\', \'hint-position\', \'top left\', 0),
				(\'product\', \'hint-opacity\', \'75\', 0),
				(\'product\', \'scroll-style\', \'default\', 0),
				(\'product\', \'show-image-title\', \'Yes\', 0),
				(\'product\', \'loop\', \'continue\', 0),
				(\'product\', \'speed\', \'0\', 0),
				(\'product\', \'width\', \'0\', 0),
				(\'product\', \'height\', \'0\', 0),
				(\'product\', \'item-width\', \'0\', 0),
				(\'product\', \'item-height\', \'0\', 0),
				(\'product\', \'step\', \'3\', 0),
				(\'product\', \'items\', \'3\', 0),
				(\'product\', \'arrows\', \'outside\', 0),
				(\'product\', \'arrows-opacity\', \'60\', 0),
				(\'product\', \'arrows-hover-opacity\', \'100\', 0),
				(\'product\', \'slider-size\', \'10%\', 0),
				(\'product\', \'slider\', \'false\', 0),
				(\'product\', \'duration\', \'1000\', 0),
				(\'category\', \'thumb-image\', \'home\', 1),
				(\'category\', \'selector-image\', \'small\', 0),
				(\'category\', \'large-image\', \'thickbox\', 0),
				(\'category\', \'zoom-width\', \'300\', 0),
				(\'category\', \'zoom-height\', \'300\', 0),
				(\'category\', \'zoom-position\', \'right\', 0),
				(\'category\', \'zoom-align\', \'top\', 0),
				(\'category\', \'zoom-distance\', \'15\', 0),
				(\'category\', \'opacity\', \'50\', 0),
				(\'category\', \'opacity-reverse\', \'No\', 0),
				(\'category\', \'zoom-fade\', \'Yes\', 0),
				(\'category\', \'zoom-window-effect\', \'shadow\', 0),
				(\'category\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'category\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'category\', \'fps\', \'25\', 0),
				(\'category\', \'smoothing\', \'Yes\', 0),
				(\'category\', \'smoothing-speed\', \'40\', 0),
				(\'category\', \'initialize-on\', \'load\', 0),
				(\'category\', \'click-to-activate\', \'No\', 0),
				(\'category\', \'click-to-deactivate\', \'No\', 0),
				(\'category\', \'show-loading\', \'Yes\', 0),
				(\'category\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'category\', \'loading-opacity\', \'75\', 0),
				(\'category\', \'loading-position-x\', \'-1\', 0),
				(\'category\', \'loading-position-y\', \'-1\', 0),
				(\'category\', \'entire-image\', \'No\', 0),
				(\'category\', \'show-title\', \'top\', 0),
				(\'category\', \'enable-effect\', \'No\', 1),
				(\'category\', \'link-to-product-page\', \'Yes\', 0),
				(\'category\', \'show-message\', \'No\', 1),
				(\'category\', \'message\', \'Move your mouse over image\', 0),
				(\'category\', \'right-click\', \'No\', 0),
				(\'category\', \'disable-zoom\', \'No\', 0),
				(\'category\', \'always-show-zoom\', \'No\', 0),
				(\'category\', \'drag-mode\', \'No\', 0),
				(\'category\', \'move-on-click\', \'Yes\', 0),
				(\'category\', \'x\', \'-1\', 0),
				(\'category\', \'y\', \'-1\', 0),
				(\'category\', \'preserve-position\', \'No\', 0),
				(\'category\', \'fit-zoom-window\', \'Yes\', 0),
				(\'category\', \'hint\', \'Yes\', 0),
				(\'category\', \'hint-text\', \'Zoom\', 0),
				(\'category\', \'hint-position\', \'top left\', 0),
				(\'category\', \'hint-opacity\', \'75\', 0),
				(\'manufacturer\', \'thumb-image\', \'home\', 1),
				(\'manufacturer\', \'selector-image\', \'small\', 0),
				(\'manufacturer\', \'large-image\', \'thickbox\', 0),
				(\'manufacturer\', \'zoom-width\', \'300\', 0),
				(\'manufacturer\', \'zoom-height\', \'300\', 0),
				(\'manufacturer\', \'zoom-position\', \'right\', 0),
				(\'manufacturer\', \'zoom-align\', \'top\', 0),
				(\'manufacturer\', \'zoom-distance\', \'15\', 0),
				(\'manufacturer\', \'opacity\', \'50\', 0),
				(\'manufacturer\', \'opacity-reverse\', \'No\', 0),
				(\'manufacturer\', \'zoom-fade\', \'Yes\', 0),
				(\'manufacturer\', \'zoom-window-effect\', \'shadow\', 0),
				(\'manufacturer\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'manufacturer\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'manufacturer\', \'fps\', \'25\', 0),
				(\'manufacturer\', \'smoothing\', \'Yes\', 0),
				(\'manufacturer\', \'smoothing-speed\', \'40\', 0),
				(\'manufacturer\', \'initialize-on\', \'load\', 0),
				(\'manufacturer\', \'click-to-activate\', \'No\', 0),
				(\'manufacturer\', \'click-to-deactivate\', \'No\', 0),
				(\'manufacturer\', \'show-loading\', \'Yes\', 0),
				(\'manufacturer\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'manufacturer\', \'loading-opacity\', \'75\', 0),
				(\'manufacturer\', \'loading-position-x\', \'-1\', 0),
				(\'manufacturer\', \'loading-position-y\', \'-1\', 0),
				(\'manufacturer\', \'entire-image\', \'No\', 0),
				(\'manufacturer\', \'show-title\', \'top\', 0),
				(\'manufacturer\', \'enable-effect\', \'No\', 1),
				(\'manufacturer\', \'link-to-product-page\', \'Yes\', 0),
				(\'manufacturer\', \'show-message\', \'No\', 1),
				(\'manufacturer\', \'message\', \'Move your mouse over image\', 0),
				(\'manufacturer\', \'right-click\', \'No\', 0),
				(\'manufacturer\', \'disable-zoom\', \'No\', 0),
				(\'manufacturer\', \'always-show-zoom\', \'No\', 0),
				(\'manufacturer\', \'drag-mode\', \'No\', 0),
				(\'manufacturer\', \'move-on-click\', \'Yes\', 0),
				(\'manufacturer\', \'x\', \'-1\', 0),
				(\'manufacturer\', \'y\', \'-1\', 0),
				(\'manufacturer\', \'preserve-position\', \'No\', 0),
				(\'manufacturer\', \'fit-zoom-window\', \'Yes\', 0),
				(\'manufacturer\', \'hint\', \'Yes\', 0),
				(\'manufacturer\', \'hint-text\', \'Zoom\', 0),
				(\'manufacturer\', \'hint-position\', \'top left\', 0),
				(\'manufacturer\', \'hint-opacity\', \'75\', 0),
				(\'newproductpage\', \'thumb-image\', \'home\', 1),
				(\'newproductpage\', \'selector-image\', \'small\', 0),
				(\'newproductpage\', \'large-image\', \'thickbox\', 0),
				(\'newproductpage\', \'zoom-width\', \'300\', 0),
				(\'newproductpage\', \'zoom-height\', \'300\', 0),
				(\'newproductpage\', \'zoom-position\', \'right\', 0),
				(\'newproductpage\', \'zoom-align\', \'top\', 0),
				(\'newproductpage\', \'zoom-distance\', \'15\', 0),
				(\'newproductpage\', \'opacity\', \'50\', 0),
				(\'newproductpage\', \'opacity-reverse\', \'No\', 0),
				(\'newproductpage\', \'zoom-fade\', \'Yes\', 0),
				(\'newproductpage\', \'zoom-window-effect\', \'shadow\', 0),
				(\'newproductpage\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'newproductpage\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'newproductpage\', \'fps\', \'25\', 0),
				(\'newproductpage\', \'smoothing\', \'Yes\', 0),
				(\'newproductpage\', \'smoothing-speed\', \'40\', 0),
				(\'newproductpage\', \'initialize-on\', \'load\', 0),
				(\'newproductpage\', \'click-to-activate\', \'No\', 0),
				(\'newproductpage\', \'click-to-deactivate\', \'No\', 0),
				(\'newproductpage\', \'show-loading\', \'Yes\', 0),
				(\'newproductpage\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'newproductpage\', \'loading-opacity\', \'75\', 0),
				(\'newproductpage\', \'loading-position-x\', \'-1\', 0),
				(\'newproductpage\', \'loading-position-y\', \'-1\', 0),
				(\'newproductpage\', \'entire-image\', \'No\', 0),
				(\'newproductpage\', \'show-title\', \'top\', 0),
				(\'newproductpage\', \'enable-effect\', \'No\', 1),
				(\'newproductpage\', \'link-to-product-page\', \'Yes\', 0),
				(\'newproductpage\', \'show-message\', \'No\', 1),
				(\'newproductpage\', \'message\', \'Move your mouse over image\', 0),
				(\'newproductpage\', \'right-click\', \'No\', 0),
				(\'newproductpage\', \'disable-zoom\', \'No\', 0),
				(\'newproductpage\', \'always-show-zoom\', \'No\', 0),
				(\'newproductpage\', \'drag-mode\', \'No\', 0),
				(\'newproductpage\', \'move-on-click\', \'Yes\', 0),
				(\'newproductpage\', \'x\', \'-1\', 0),
				(\'newproductpage\', \'y\', \'-1\', 0),
				(\'newproductpage\', \'preserve-position\', \'No\', 0),
				(\'newproductpage\', \'fit-zoom-window\', \'Yes\', 0),
				(\'newproductpage\', \'hint\', \'Yes\', 0),
				(\'newproductpage\', \'hint-text\', \'Zoom\', 0),
				(\'newproductpage\', \'hint-position\', \'top left\', 0),
				(\'newproductpage\', \'hint-opacity\', \'75\', 0),
				(\'blocknewproducts\', \'thumb-image\', \'medium\', 1),
				(\'blocknewproducts\', \'selector-image\', \'small\', 0),
				(\'blocknewproducts\', \'large-image\', \'thickbox\', 0),
				(\'blocknewproducts\', \'zoom-width\', \'300\', 0),
				(\'blocknewproducts\', \'zoom-height\', \'300\', 0),
				(\'blocknewproducts\', \'zoom-position\', \'left\', 1),
				(\'blocknewproducts\', \'zoom-align\', \'top\', 0),
				(\'blocknewproducts\', \'zoom-distance\', \'15\', 0),
				(\'blocknewproducts\', \'opacity\', \'50\', 0),
				(\'blocknewproducts\', \'opacity-reverse\', \'No\', 0),
				(\'blocknewproducts\', \'zoom-fade\', \'Yes\', 0),
				(\'blocknewproducts\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blocknewproducts\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blocknewproducts\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blocknewproducts\', \'fps\', \'25\', 0),
				(\'blocknewproducts\', \'smoothing\', \'Yes\', 0),
				(\'blocknewproducts\', \'smoothing-speed\', \'40\', 0),
				(\'blocknewproducts\', \'initialize-on\', \'load\', 0),
				(\'blocknewproducts\', \'click-to-activate\', \'No\', 0),
				(\'blocknewproducts\', \'click-to-deactivate\', \'No\', 0),
				(\'blocknewproducts\', \'show-loading\', \'Yes\', 0),
				(\'blocknewproducts\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blocknewproducts\', \'loading-opacity\', \'75\', 0),
				(\'blocknewproducts\', \'loading-position-x\', \'-1\', 0),
				(\'blocknewproducts\', \'loading-position-y\', \'-1\', 0),
				(\'blocknewproducts\', \'entire-image\', \'No\', 0),
				(\'blocknewproducts\', \'show-title\', \'top\', 0),
				(\'blocknewproducts\', \'enable-effect\', \'No\', 1),
				(\'blocknewproducts\', \'link-to-product-page\', \'Yes\', 0),
				(\'blocknewproducts\', \'show-message\', \'No\', 1),
				(\'blocknewproducts\', \'message\', \'Move your mouse over image\', 0),
				(\'blocknewproducts\', \'right-click\', \'No\', 0),
				(\'blocknewproducts\', \'disable-zoom\', \'No\', 0),
				(\'blocknewproducts\', \'always-show-zoom\', \'No\', 0),
				(\'blocknewproducts\', \'drag-mode\', \'No\', 0),
				(\'blocknewproducts\', \'move-on-click\', \'Yes\', 0),
				(\'blocknewproducts\', \'x\', \'-1\', 0),
				(\'blocknewproducts\', \'y\', \'-1\', 0),
				(\'blocknewproducts\', \'preserve-position\', \'No\', 0),
				(\'blocknewproducts\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blocknewproducts\', \'hint\', \'Yes\', 0),
				(\'blocknewproducts\', \'hint-text\', \'Zoom\', 0),
				(\'blocknewproducts\', \'hint-position\', \'top left\', 0),
				(\'blocknewproducts\', \'hint-opacity\', \'75\', 0),
				(\'blocknewproducts_home\', \'thumb-image\', \'home\', 1),
				(\'blocknewproducts_home\', \'selector-image\', \'small\', 0),
				(\'blocknewproducts_home\', \'large-image\', \'thickbox\', 0),
				(\'blocknewproducts_home\', \'zoom-width\', \'300\', 0),
				(\'blocknewproducts_home\', \'zoom-height\', \'300\', 0),
				(\'blocknewproducts_home\', \'zoom-position\', \'right\', 0),
				(\'blocknewproducts_home\', \'zoom-align\', \'top\', 0),
				(\'blocknewproducts_home\', \'zoom-distance\', \'15\', 0),
				(\'blocknewproducts_home\', \'opacity\', \'50\', 0),
				(\'blocknewproducts_home\', \'opacity-reverse\', \'No\', 0),
				(\'blocknewproducts_home\', \'zoom-fade\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blocknewproducts_home\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blocknewproducts_home\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blocknewproducts_home\', \'fps\', \'25\', 0),
				(\'blocknewproducts_home\', \'smoothing\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'smoothing-speed\', \'40\', 0),
				(\'blocknewproducts_home\', \'initialize-on\', \'load\', 0),
				(\'blocknewproducts_home\', \'click-to-activate\', \'No\', 0),
				(\'blocknewproducts_home\', \'click-to-deactivate\', \'No\', 0),
				(\'blocknewproducts_home\', \'show-loading\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blocknewproducts_home\', \'loading-opacity\', \'75\', 0),
				(\'blocknewproducts_home\', \'loading-position-x\', \'-1\', 0),
				(\'blocknewproducts_home\', \'loading-position-y\', \'-1\', 0),
				(\'blocknewproducts_home\', \'entire-image\', \'No\', 0),
				(\'blocknewproducts_home\', \'show-title\', \'top\', 0),
				(\'blocknewproducts_home\', \'enable-effect\', \'No\', 1),
				(\'blocknewproducts_home\', \'link-to-product-page\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'show-message\', \'No\', 1),
				(\'blocknewproducts_home\', \'message\', \'Move your mouse over image\', 0),
				(\'blocknewproducts_home\', \'right-click\', \'No\', 0),
				(\'blocknewproducts_home\', \'disable-zoom\', \'No\', 0),
				(\'blocknewproducts_home\', \'always-show-zoom\', \'No\', 0),
				(\'blocknewproducts_home\', \'drag-mode\', \'No\', 0),
				(\'blocknewproducts_home\', \'move-on-click\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'x\', \'-1\', 0),
				(\'blocknewproducts_home\', \'y\', \'-1\', 0),
				(\'blocknewproducts_home\', \'preserve-position\', \'No\', 0),
				(\'blocknewproducts_home\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'hint\', \'Yes\', 0),
				(\'blocknewproducts_home\', \'hint-text\', \'Zoom\', 0),
				(\'blocknewproducts_home\', \'hint-position\', \'top left\', 0),
				(\'blocknewproducts_home\', \'hint-opacity\', \'75\', 0),
				(\'bestsellerspage\', \'thumb-image\', \'home\', 1),
				(\'bestsellerspage\', \'selector-image\', \'small\', 0),
				(\'bestsellerspage\', \'large-image\', \'thickbox\', 0),
				(\'bestsellerspage\', \'zoom-width\', \'300\', 0),
				(\'bestsellerspage\', \'zoom-height\', \'300\', 0),
				(\'bestsellerspage\', \'zoom-position\', \'right\', 0),
				(\'bestsellerspage\', \'zoom-align\', \'top\', 0),
				(\'bestsellerspage\', \'zoom-distance\', \'15\', 0),
				(\'bestsellerspage\', \'opacity\', \'50\', 0),
				(\'bestsellerspage\', \'opacity-reverse\', \'No\', 0),
				(\'bestsellerspage\', \'zoom-fade\', \'Yes\', 0),
				(\'bestsellerspage\', \'zoom-window-effect\', \'shadow\', 0),
				(\'bestsellerspage\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'bestsellerspage\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'bestsellerspage\', \'fps\', \'25\', 0),
				(\'bestsellerspage\', \'smoothing\', \'Yes\', 0),
				(\'bestsellerspage\', \'smoothing-speed\', \'40\', 0),
				(\'bestsellerspage\', \'initialize-on\', \'load\', 0),
				(\'bestsellerspage\', \'click-to-activate\', \'No\', 0),
				(\'bestsellerspage\', \'click-to-deactivate\', \'No\', 0),
				(\'bestsellerspage\', \'show-loading\', \'Yes\', 0),
				(\'bestsellerspage\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'bestsellerspage\', \'loading-opacity\', \'75\', 0),
				(\'bestsellerspage\', \'loading-position-x\', \'-1\', 0),
				(\'bestsellerspage\', \'loading-position-y\', \'-1\', 0),
				(\'bestsellerspage\', \'entire-image\', \'No\', 0),
				(\'bestsellerspage\', \'show-title\', \'top\', 0),
				(\'bestsellerspage\', \'enable-effect\', \'No\', 1),
				(\'bestsellerspage\', \'link-to-product-page\', \'Yes\', 0),
				(\'bestsellerspage\', \'show-message\', \'No\', 1),
				(\'bestsellerspage\', \'message\', \'Move your mouse over image\', 0),
				(\'bestsellerspage\', \'right-click\', \'No\', 0),
				(\'bestsellerspage\', \'disable-zoom\', \'No\', 0),
				(\'bestsellerspage\', \'always-show-zoom\', \'No\', 0),
				(\'bestsellerspage\', \'drag-mode\', \'No\', 0),
				(\'bestsellerspage\', \'move-on-click\', \'Yes\', 0),
				(\'bestsellerspage\', \'x\', \'-1\', 0),
				(\'bestsellerspage\', \'y\', \'-1\', 0),
				(\'bestsellerspage\', \'preserve-position\', \'No\', 0),
				(\'bestsellerspage\', \'fit-zoom-window\', \'Yes\', 0),
				(\'bestsellerspage\', \'hint\', \'Yes\', 0),
				(\'bestsellerspage\', \'hint-text\', \'Zoom\', 0),
				(\'bestsellerspage\', \'hint-position\', \'top left\', 0),
				(\'bestsellerspage\', \'hint-opacity\', \'75\', 0),
				(\'blockbestsellers\', \'thumb-image\', \'medium\', 1),
				(\'blockbestsellers\', \'selector-image\', \'small\', 0),
				(\'blockbestsellers\', \'large-image\', \'thickbox\', 0),
				(\'blockbestsellers\', \'zoom-width\', \'300\', 0),
				(\'blockbestsellers\', \'zoom-height\', \'300\', 0),
				(\'blockbestsellers\', \'zoom-position\', \'left\', 1),
				(\'blockbestsellers\', \'zoom-align\', \'top\', 0),
				(\'blockbestsellers\', \'zoom-distance\', \'15\', 0),
				(\'blockbestsellers\', \'opacity\', \'50\', 0),
				(\'blockbestsellers\', \'opacity-reverse\', \'No\', 0),
				(\'blockbestsellers\', \'zoom-fade\', \'Yes\', 0),
				(\'blockbestsellers\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blockbestsellers\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blockbestsellers\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blockbestsellers\', \'fps\', \'25\', 0),
				(\'blockbestsellers\', \'smoothing\', \'Yes\', 0),
				(\'blockbestsellers\', \'smoothing-speed\', \'40\', 0),
				(\'blockbestsellers\', \'initialize-on\', \'load\', 0),
				(\'blockbestsellers\', \'click-to-activate\', \'No\', 0),
				(\'blockbestsellers\', \'click-to-deactivate\', \'No\', 0),
				(\'blockbestsellers\', \'show-loading\', \'Yes\', 0),
				(\'blockbestsellers\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blockbestsellers\', \'loading-opacity\', \'75\', 0),
				(\'blockbestsellers\', \'loading-position-x\', \'-1\', 0),
				(\'blockbestsellers\', \'loading-position-y\', \'-1\', 0),
				(\'blockbestsellers\', \'entire-image\', \'No\', 0),
				(\'blockbestsellers\', \'show-title\', \'top\', 0),
				(\'blockbestsellers\', \'enable-effect\', \'No\', 1),
				(\'blockbestsellers\', \'link-to-product-page\', \'Yes\', 0),
				(\'blockbestsellers\', \'show-message\', \'No\', 1),
				(\'blockbestsellers\', \'message\', \'Move your mouse over image\', 0),
				(\'blockbestsellers\', \'right-click\', \'No\', 0),
				(\'blockbestsellers\', \'disable-zoom\', \'No\', 0),
				(\'blockbestsellers\', \'always-show-zoom\', \'No\', 0),
				(\'blockbestsellers\', \'drag-mode\', \'No\', 0),
				(\'blockbestsellers\', \'move-on-click\', \'Yes\', 0),
				(\'blockbestsellers\', \'x\', \'-1\', 0),
				(\'blockbestsellers\', \'y\', \'-1\', 0),
				(\'blockbestsellers\', \'preserve-position\', \'No\', 0),
				(\'blockbestsellers\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blockbestsellers\', \'hint\', \'Yes\', 0),
				(\'blockbestsellers\', \'hint-text\', \'Zoom\', 0),
				(\'blockbestsellers\', \'hint-position\', \'top left\', 0),
				(\'blockbestsellers\', \'hint-opacity\', \'75\', 0),
				(\'blockbestsellers_home\', \'thumb-image\', \'home\', 1),
				(\'blockbestsellers_home\', \'selector-image\', \'small\', 0),
				(\'blockbestsellers_home\', \'large-image\', \'thickbox\', 0),
				(\'blockbestsellers_home\', \'zoom-width\', \'300\', 0),
				(\'blockbestsellers_home\', \'zoom-height\', \'300\', 0),
				(\'blockbestsellers_home\', \'zoom-position\', \'right\', 0),
				(\'blockbestsellers_home\', \'zoom-align\', \'top\', 0),
				(\'blockbestsellers_home\', \'zoom-distance\', \'15\', 0),
				(\'blockbestsellers_home\', \'opacity\', \'50\', 0),
				(\'blockbestsellers_home\', \'opacity-reverse\', \'No\', 0),
				(\'blockbestsellers_home\', \'zoom-fade\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blockbestsellers_home\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blockbestsellers_home\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blockbestsellers_home\', \'fps\', \'25\', 0),
				(\'blockbestsellers_home\', \'smoothing\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'smoothing-speed\', \'40\', 0),
				(\'blockbestsellers_home\', \'initialize-on\', \'load\', 0),
				(\'blockbestsellers_home\', \'click-to-activate\', \'No\', 0),
				(\'blockbestsellers_home\', \'click-to-deactivate\', \'No\', 0),
				(\'blockbestsellers_home\', \'show-loading\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blockbestsellers_home\', \'loading-opacity\', \'75\', 0),
				(\'blockbestsellers_home\', \'loading-position-x\', \'-1\', 0),
				(\'blockbestsellers_home\', \'loading-position-y\', \'-1\', 0),
				(\'blockbestsellers_home\', \'entire-image\', \'No\', 0),
				(\'blockbestsellers_home\', \'show-title\', \'top\', 0),
				(\'blockbestsellers_home\', \'enable-effect\', \'No\', 1),
				(\'blockbestsellers_home\', \'link-to-product-page\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'show-message\', \'No\', 1),
				(\'blockbestsellers_home\', \'message\', \'Move your mouse over image\', 0),
				(\'blockbestsellers_home\', \'right-click\', \'No\', 0),
				(\'blockbestsellers_home\', \'disable-zoom\', \'No\', 0),
				(\'blockbestsellers_home\', \'always-show-zoom\', \'No\', 0),
				(\'blockbestsellers_home\', \'drag-mode\', \'No\', 0),
				(\'blockbestsellers_home\', \'move-on-click\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'x\', \'-1\', 0),
				(\'blockbestsellers_home\', \'y\', \'-1\', 0),
				(\'blockbestsellers_home\', \'preserve-position\', \'No\', 0),
				(\'blockbestsellers_home\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'hint\', \'Yes\', 0),
				(\'blockbestsellers_home\', \'hint-text\', \'Zoom\', 0),
				(\'blockbestsellers_home\', \'hint-position\', \'top left\', 0),
				(\'blockbestsellers_home\', \'hint-opacity\', \'75\', 0),
				(\'specialspage\', \'thumb-image\', \'home\', 1),
				(\'specialspage\', \'selector-image\', \'small\', 0),
				(\'specialspage\', \'large-image\', \'thickbox\', 0),
				(\'specialspage\', \'zoom-width\', \'300\', 0),
				(\'specialspage\', \'zoom-height\', \'300\', 0),
				(\'specialspage\', \'zoom-position\', \'right\', 0),
				(\'specialspage\', \'zoom-align\', \'top\', 0),
				(\'specialspage\', \'zoom-distance\', \'15\', 0),
				(\'specialspage\', \'opacity\', \'50\', 0),
				(\'specialspage\', \'opacity-reverse\', \'No\', 0),
				(\'specialspage\', \'zoom-fade\', \'Yes\', 0),
				(\'specialspage\', \'zoom-window-effect\', \'shadow\', 0),
				(\'specialspage\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'specialspage\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'specialspage\', \'fps\', \'25\', 0),
				(\'specialspage\', \'smoothing\', \'Yes\', 0),
				(\'specialspage\', \'smoothing-speed\', \'40\', 0),
				(\'specialspage\', \'initialize-on\', \'load\', 0),
				(\'specialspage\', \'click-to-activate\', \'No\', 0),
				(\'specialspage\', \'click-to-deactivate\', \'No\', 0),
				(\'specialspage\', \'show-loading\', \'Yes\', 0),
				(\'specialspage\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'specialspage\', \'loading-opacity\', \'75\', 0),
				(\'specialspage\', \'loading-position-x\', \'-1\', 0),
				(\'specialspage\', \'loading-position-y\', \'-1\', 0),
				(\'specialspage\', \'entire-image\', \'No\', 0),
				(\'specialspage\', \'show-title\', \'top\', 0),
				(\'specialspage\', \'enable-effect\', \'No\', 1),
				(\'specialspage\', \'link-to-product-page\', \'Yes\', 0),
				(\'specialspage\', \'show-message\', \'No\', 1),
				(\'specialspage\', \'message\', \'Move your mouse over image\', 0),
				(\'specialspage\', \'right-click\', \'No\', 0),
				(\'specialspage\', \'disable-zoom\', \'No\', 0),
				(\'specialspage\', \'always-show-zoom\', \'No\', 0),
				(\'specialspage\', \'drag-mode\', \'No\', 0),
				(\'specialspage\', \'move-on-click\', \'Yes\', 0),
				(\'specialspage\', \'x\', \'-1\', 0),
				(\'specialspage\', \'y\', \'-1\', 0),
				(\'specialspage\', \'preserve-position\', \'No\', 0),
				(\'specialspage\', \'fit-zoom-window\', \'Yes\', 0),
				(\'specialspage\', \'hint\', \'Yes\', 0),
				(\'specialspage\', \'hint-text\', \'Zoom\', 0),
				(\'specialspage\', \'hint-position\', \'top left\', 0),
				(\'specialspage\', \'hint-opacity\', \'75\', 0),
				(\'blockspecials\', \'thumb-image\', \'medium\', 1),
				(\'blockspecials\', \'selector-image\', \'small\', 0),
				(\'blockspecials\', \'large-image\', \'thickbox\', 0),
				(\'blockspecials\', \'zoom-width\', \'300\', 0),
				(\'blockspecials\', \'zoom-height\', \'300\', 0),
				(\'blockspecials\', \'zoom-position\', \'left\', 1),
				(\'blockspecials\', \'zoom-align\', \'top\', 0),
				(\'blockspecials\', \'zoom-distance\', \'15\', 0),
				(\'blockspecials\', \'opacity\', \'50\', 0),
				(\'blockspecials\', \'opacity-reverse\', \'No\', 0),
				(\'blockspecials\', \'zoom-fade\', \'Yes\', 0),
				(\'blockspecials\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blockspecials\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blockspecials\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blockspecials\', \'fps\', \'25\', 0),
				(\'blockspecials\', \'smoothing\', \'Yes\', 0),
				(\'blockspecials\', \'smoothing-speed\', \'40\', 0),
				(\'blockspecials\', \'initialize-on\', \'load\', 0),
				(\'blockspecials\', \'click-to-activate\', \'No\', 0),
				(\'blockspecials\', \'click-to-deactivate\', \'No\', 0),
				(\'blockspecials\', \'show-loading\', \'Yes\', 0),
				(\'blockspecials\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blockspecials\', \'loading-opacity\', \'75\', 0),
				(\'blockspecials\', \'loading-position-x\', \'-1\', 0),
				(\'blockspecials\', \'loading-position-y\', \'-1\', 0),
				(\'blockspecials\', \'entire-image\', \'No\', 0),
				(\'blockspecials\', \'show-title\', \'top\', 0),
				(\'blockspecials\', \'enable-effect\', \'No\', 1),
				(\'blockspecials\', \'link-to-product-page\', \'Yes\', 0),
				(\'blockspecials\', \'show-message\', \'No\', 1),
				(\'blockspecials\', \'message\', \'Move your mouse over image\', 0),
				(\'blockspecials\', \'right-click\', \'No\', 0),
				(\'blockspecials\', \'disable-zoom\', \'No\', 0),
				(\'blockspecials\', \'always-show-zoom\', \'No\', 0),
				(\'blockspecials\', \'drag-mode\', \'No\', 0),
				(\'blockspecials\', \'move-on-click\', \'Yes\', 0),
				(\'blockspecials\', \'x\', \'-1\', 0),
				(\'blockspecials\', \'y\', \'-1\', 0),
				(\'blockspecials\', \'preserve-position\', \'No\', 0),
				(\'blockspecials\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blockspecials\', \'hint\', \'Yes\', 0),
				(\'blockspecials\', \'hint-text\', \'Zoom\', 0),
				(\'blockspecials\', \'hint-position\', \'top left\', 0),
				(\'blockspecials\', \'hint-opacity\', \'75\', 0),
				(\'blockviewed\', \'thumb-image\', \'medium\', 1),
				(\'blockviewed\', \'selector-image\', \'small\', 0),
				(\'blockviewed\', \'large-image\', \'thickbox\', 0),
				(\'blockviewed\', \'zoom-width\', \'300\', 0),
				(\'blockviewed\', \'zoom-height\', \'300\', 0),
				(\'blockviewed\', \'zoom-position\', \'right\', 0),
				(\'blockviewed\', \'zoom-align\', \'top\', 0),
				(\'blockviewed\', \'zoom-distance\', \'15\', 0),
				(\'blockviewed\', \'opacity\', \'50\', 0),
				(\'blockviewed\', \'opacity-reverse\', \'No\', 0),
				(\'blockviewed\', \'zoom-fade\', \'Yes\', 0),
				(\'blockviewed\', \'zoom-window-effect\', \'shadow\', 0),
				(\'blockviewed\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'blockviewed\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'blockviewed\', \'fps\', \'25\', 0),
				(\'blockviewed\', \'smoothing\', \'Yes\', 0),
				(\'blockviewed\', \'smoothing-speed\', \'40\', 0),
				(\'blockviewed\', \'initialize-on\', \'load\', 0),
				(\'blockviewed\', \'click-to-activate\', \'No\', 0),
				(\'blockviewed\', \'click-to-deactivate\', \'No\', 0),
				(\'blockviewed\', \'show-loading\', \'Yes\', 0),
				(\'blockviewed\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'blockviewed\', \'loading-opacity\', \'75\', 0),
				(\'blockviewed\', \'loading-position-x\', \'-1\', 0),
				(\'blockviewed\', \'loading-position-y\', \'-1\', 0),
				(\'blockviewed\', \'entire-image\', \'No\', 0),
				(\'blockviewed\', \'show-title\', \'top\', 0),
				(\'blockviewed\', \'enable-effect\', \'No\', 1),
				(\'blockviewed\', \'link-to-product-page\', \'Yes\', 0),
				(\'blockviewed\', \'show-message\', \'No\', 1),
				(\'blockviewed\', \'message\', \'Move your mouse over image\', 0),
				(\'blockviewed\', \'right-click\', \'No\', 0),
				(\'blockviewed\', \'disable-zoom\', \'No\', 0),
				(\'blockviewed\', \'always-show-zoom\', \'No\', 0),
				(\'blockviewed\', \'drag-mode\', \'No\', 0),
				(\'blockviewed\', \'move-on-click\', \'Yes\', 0),
				(\'blockviewed\', \'x\', \'-1\', 0),
				(\'blockviewed\', \'y\', \'-1\', 0),
				(\'blockviewed\', \'preserve-position\', \'No\', 0),
				(\'blockviewed\', \'fit-zoom-window\', \'Yes\', 0),
				(\'blockviewed\', \'hint\', \'Yes\', 0),
				(\'blockviewed\', \'hint-text\', \'Zoom\', 0),
				(\'blockviewed\', \'hint-position\', \'top left\', 0),
				(\'blockviewed\', \'hint-opacity\', \'75\', 0),
				(\'homefeatured\', \'thumb-image\', \'home\', 1),
				(\'homefeatured\', \'selector-image\', \'small\', 0),
				(\'homefeatured\', \'large-image\', \'thickbox\', 0),
				(\'homefeatured\', \'zoom-width\', \'300\', 0),
				(\'homefeatured\', \'zoom-height\', \'300\', 0),
				(\'homefeatured\', \'zoom-position\', \'right\', 0),
				(\'homefeatured\', \'zoom-align\', \'top\', 0),
				(\'homefeatured\', \'zoom-distance\', \'15\', 0),
				(\'homefeatured\', \'opacity\', \'50\', 0),
				(\'homefeatured\', \'opacity-reverse\', \'No\', 0),
				(\'homefeatured\', \'zoom-fade\', \'Yes\', 0),
				(\'homefeatured\', \'zoom-window-effect\', \'shadow\', 0),
				(\'homefeatured\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'homefeatured\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'homefeatured\', \'fps\', \'25\', 0),
				(\'homefeatured\', \'smoothing\', \'Yes\', 0),
				(\'homefeatured\', \'smoothing-speed\', \'40\', 0),
				(\'homefeatured\', \'initialize-on\', \'load\', 0),
				(\'homefeatured\', \'click-to-activate\', \'No\', 0),
				(\'homefeatured\', \'click-to-deactivate\', \'No\', 0),
				(\'homefeatured\', \'show-loading\', \'Yes\', 0),
				(\'homefeatured\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'homefeatured\', \'loading-opacity\', \'75\', 0),
				(\'homefeatured\', \'loading-position-x\', \'-1\', 0),
				(\'homefeatured\', \'loading-position-y\', \'-1\', 0),
				(\'homefeatured\', \'entire-image\', \'No\', 0),
				(\'homefeatured\', \'show-title\', \'top\', 0),
				(\'homefeatured\', \'enable-effect\', \'No\', 1),
				(\'homefeatured\', \'link-to-product-page\', \'Yes\', 0),
				(\'homefeatured\', \'show-message\', \'No\', 1),
				(\'homefeatured\', \'message\', \'Move your mouse over image\', 0),
				(\'homefeatured\', \'right-click\', \'No\', 0),
				(\'homefeatured\', \'disable-zoom\', \'No\', 0),
				(\'homefeatured\', \'always-show-zoom\', \'No\', 0),
				(\'homefeatured\', \'drag-mode\', \'No\', 0),
				(\'homefeatured\', \'move-on-click\', \'Yes\', 0),
				(\'homefeatured\', \'x\', \'-1\', 0),
				(\'homefeatured\', \'y\', \'-1\', 0),
				(\'homefeatured\', \'preserve-position\', \'No\', 0),
				(\'homefeatured\', \'fit-zoom-window\', \'Yes\', 0),
				(\'homefeatured\', \'hint\', \'Yes\', 0),
				(\'homefeatured\', \'hint-text\', \'Zoom\', 0),
				(\'homefeatured\', \'hint-position\', \'top left\', 0),
				(\'homefeatured\', \'hint-opacity\', \'75\', 0),
				(\'search\', \'thumb-image\', \'home\', 1),
				(\'search\', \'selector-image\', \'small\', 0),
				(\'search\', \'large-image\', \'thickbox\', 0),
				(\'search\', \'zoom-width\', \'300\', 0),
				(\'search\', \'zoom-height\', \'300\', 0),
				(\'search\', \'zoom-position\', \'right\', 0),
				(\'search\', \'zoom-align\', \'top\', 0),
				(\'search\', \'zoom-distance\', \'15\', 0),
				(\'search\', \'opacity\', \'50\', 0),
				(\'search\', \'opacity-reverse\', \'No\', 0),
				(\'search\', \'zoom-fade\', \'Yes\', 0),
				(\'search\', \'zoom-window-effect\', \'shadow\', 0),
				(\'search\', \'zoom-fade-in-speed\', \'200\', 0),
				(\'search\', \'zoom-fade-out-speed\', \'200\', 0),
				(\'search\', \'fps\', \'25\', 0),
				(\'search\', \'smoothing\', \'Yes\', 0),
				(\'search\', \'smoothing-speed\', \'40\', 0),
				(\'search\', \'initialize-on\', \'load\', 0),
				(\'search\', \'click-to-activate\', \'No\', 0),
				(\'search\', \'click-to-deactivate\', \'No\', 0),
				(\'search\', \'show-loading\', \'Yes\', 0),
				(\'search\', \'loading-msg\', \'Loading zoom...\', 0),
				(\'search\', \'loading-opacity\', \'75\', 0),
				(\'search\', \'loading-position-x\', \'-1\', 0),
				(\'search\', \'loading-position-y\', \'-1\', 0),
				(\'search\', \'entire-image\', \'No\', 0),
				(\'search\', \'show-title\', \'top\', 0),
				(\'search\', \'enable-effect\', \'No\', 1),
				(\'search\', \'link-to-product-page\', \'Yes\', 0),
				(\'search\', \'show-message\', \'No\', 1),
				(\'search\', \'message\', \'Move your mouse over image\', 0),
				(\'search\', \'right-click\', \'No\', 0),
				(\'search\', \'disable-zoom\', \'No\', 0),
				(\'search\', \'always-show-zoom\', \'No\', 0),
				(\'search\', \'drag-mode\', \'No\', 0),
				(\'search\', \'move-on-click\', \'Yes\', 0),
				(\'search\', \'x\', \'-1\', 0),
				(\'search\', \'y\', \'-1\', 0),
				(\'search\', \'preserve-position\', \'No\', 0),
				(\'search\', \'fit-zoom-window\', \'Yes\', 0),
				(\'search\', \'hint\', \'Yes\', 0),
				(\'search\', \'hint-text\', \'Zoom\', 0),
				(\'search\', \'hint-position\', \'top left\', 0),
				(\'search\', \'hint-opacity\', \'75\', 0)';
		if(!$this->isPrestahop16x) {
			$sql = preg_replace('/\r\n\s*..(?:blockbestsellers_home|blocknewproducts_home)\b[^\r]*+/i', '', $sql);
			$sql = rtrim($sql, ',');
		}
		return Db::getInstance()->Execute($sql);
	}

	function getBlocks() {
		$blocks = array(
			'default' => 'Defaults',
			'product' => 'Product page',
			'category' => 'Category page',
			'manufacturer' => 'Manufacturers page',
			'newproductpage' => 'New products page',
			'blocknewproducts' => 'New products sidebar',
			'blocknewproducts_home' => 'New products block',
			'bestsellerspage' => 'Bestsellers page',
			'blockbestsellers' => 'Bestsellers sidebar',
			'blockbestsellers_home' => 'Bestsellers block',
			'specialspage' => 'Specials page',
			'blockspecials' => 'Specials sidebar',
			'blockviewed' => 'Viewed sidebar',
			'homefeatured' => 'Featured block',
			'search' => 'Search page'
		);
		if(!$this->isPrestahop16x) {
			unset($blocks['blockbestsellers_home'], $blocks['blocknewproducts_home']);
		}
		return $blocks;
	}

	function getMessages() {
		return array(
			'default' => array(
				'loading-msg' => array(
					'title' => 'Defaults loading message',
					'translate' => $this->l('Defaults loading message')
				),
				'message' => array(
					'title' => 'Defaults message (under Magic Zoom)',
					'translate' => $this->l('Defaults message (under Magic Zoom)')
				)
			),
			'product' => array(
				'loading-msg' => array(
					'title' => 'Product page loading message',
					'translate' => $this->l('Product page loading message')
				),
				'message' => array(
					'title' => 'Product page message (under Magic Zoom)',
					'translate' => $this->l('Product page message (under Magic Zoom)')
				)
			),
			'category' => array(
				'loading-msg' => array(
					'title' => 'Category page loading message',
					'translate' => $this->l('Category page loading message')
				),
				'message' => array(
					'title' => 'Category page message (under Magic Zoom)',
					'translate' => $this->l('Category page message (under Magic Zoom)')
				)
			),
			'manufacturer' => array(
				'loading-msg' => array(
					'title' => 'Manufacturers page loading message',
					'translate' => $this->l('Manufacturers page loading message')
				),
				'message' => array(
					'title' => 'Manufacturers page message (under Magic Zoom)',
					'translate' => $this->l('Manufacturers page message (under Magic Zoom)')
				)
			),
			'newproductpage' => array(
				'loading-msg' => array(
					'title' => 'New products page loading message',
					'translate' => $this->l('New products page loading message')
				),
				'message' => array(
					'title' => 'New products page message (under Magic Zoom)',
					'translate' => $this->l('New products page message (under Magic Zoom)')
				)
			),
			'blocknewproducts' => array(
				'loading-msg' => array(
					'title' => 'New products sidebar loading message',
					'translate' => $this->l('New products sidebar loading message')
				),
				'message' => array(
					'title' => 'New products sidebar message (under Magic Zoom)',
					'translate' => $this->l('New products sidebar message (under Magic Zoom)')
				)
			),
			'blocknewproducts_home' => array(
				'loading-msg' => array(
					'title' => 'New products block loading message',
					'translate' => $this->l('New products block loading message')
				),
				'message' => array(
					'title' => 'New products block message (under Magic Zoom)',
					'translate' => $this->l('New products block message (under Magic Zoom)')
				)
			),
			'bestsellerspage' => array(
				'loading-msg' => array(
					'title' => 'Bestsellers page loading message',
					'translate' => $this->l('Bestsellers page loading message')
				),
				'message' => array(
					'title' => 'Bestsellers page message (under Magic Zoom)',
					'translate' => $this->l('Bestsellers page message (under Magic Zoom)')
				)
			),
			'blockbestsellers' => array(
				'loading-msg' => array(
					'title' => 'Bestsellers sidebar loading message',
					'translate' => $this->l('Bestsellers sidebar loading message')
				),
				'message' => array(
					'title' => 'Bestsellers sidebar message (under Magic Zoom)',
					'translate' => $this->l('Bestsellers sidebar message (under Magic Zoom)')
				)
			),
			'blockbestsellers_home' => array(
				'loading-msg' => array(
					'title' => 'Bestsellers block loading message',
					'translate' => $this->l('Bestsellers block loading message')
				),
				'message' => array(
					'title' => 'Bestsellers block message (under Magic Zoom)',
					'translate' => $this->l('Bestsellers block message (under Magic Zoom)')
				)
			),
			'specialspage' => array(
				'loading-msg' => array(
					'title' => 'Specials page loading message',
					'translate' => $this->l('Specials page loading message')
				),
				'message' => array(
					'title' => 'Specials page message (under Magic Zoom)',
					'translate' => $this->l('Specials page message (under Magic Zoom)')
				)
			),
			'blockspecials' => array(
				'loading-msg' => array(
					'title' => 'Specials sidebar loading message',
					'translate' => $this->l('Specials sidebar loading message')
				),
				'message' => array(
					'title' => 'Specials sidebar message (under Magic Zoom)',
					'translate' => $this->l('Specials sidebar message (under Magic Zoom)')
				)
			),
			'blockviewed' => array(
				'loading-msg' => array(
					'title' => 'Viewed sidebar loading message',
					'translate' => $this->l('Viewed sidebar loading message')
				),
				'message' => array(
					'title' => 'Viewed sidebar message (under Magic Zoom)',
					'translate' => $this->l('Viewed sidebar message (under Magic Zoom)')
				)
			),
			'homefeatured' => array(
				'loading-msg' => array(
					'title' => 'Featured block loading message',
					'translate' => $this->l('Featured block loading message')
				),
				'message' => array(
					'title' => 'Featured block message (under Magic Zoom)',
					'translate' => $this->l('Featured block message (under Magic Zoom)')
				)
			),
			'search' => array(
				'loading-msg' => array(
					'title' => 'Search page loading message',
					'translate' => $this->l('Search page loading message')
				),
				'message' => array(
					'title' => 'Search page message (under Magic Zoom)',
					'translate' => $this->l('Search page message (under Magic Zoom)')
				)
			)
		);
	}

	function getParamsMap() {
		$map = array(
			'default' => array(
				'Image type' => array(
					'thumb-image',
					'selector-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'include-headers-on-all-pages',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'product' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'General' => array(
					'template',
					'magicscroll'
				),
				'Image type' => array(
					'thumb-image',
					'selector-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Multiple images' => array(
					'selectors-margin',
					'selectors-change',
					'selectors-class',
					'preload-selectors-small',
					'preload-selectors-big',
					'selectors-effect',
					'selectors-effect-speed',
					'selectors-mouseover-delay'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				),
				'Scroll' => array(
					'scroll-style',
					'show-image-title',
					'loop',
					'speed',
					'width',
					'height',
					'item-width',
					'item-height',
					'step',
					'items'
				),
				'Scroll Arrows' => array(
					'arrows',
					'arrows-opacity',
					'arrows-hover-opacity'
				),
				'Scroll Slider' => array(
					'slider-size',
					'slider'
				),
				'Scroll effect' => array(
					'duration'
				)
			),
			'category' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'manufacturer' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'newproductpage' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blocknewproducts' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blocknewproducts_home' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'bestsellerspage' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blockbestsellers' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blockbestsellers_home' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'specialspage' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blockspecials' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'blockviewed' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'homefeatured' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			),
			'search' => array(
				'Enable effect' => array(
					'enable-effect'
				),
				'Image type' => array(
					'thumb-image',
					'large-image'
				),
				'Positioning and Geometry' => array(
					'zoom-width',
					'zoom-height',
					'zoom-position',
					'zoom-align',
					'zoom-distance'
				),
				'Effects' => array(
					'opacity',
					'opacity-reverse',
					'zoom-fade',
					'zoom-window-effect',
					'zoom-fade-in-speed',
					'zoom-fade-out-speed',
					'fps',
					'smoothing',
					'smoothing-speed'
				),
				'Initialization' => array(
					'initialize-on',
					'click-to-activate',
					'click-to-deactivate',
					'show-loading',
					'loading-msg',
					'loading-opacity',
					'loading-position-x',
					'loading-position-y',
					'entire-image'
				),
				'Title and Caption' => array(
					'show-title'
				),
				'Miscellaneous' => array(
					'link-to-product-page',
					'show-message',
					'message',
					'right-click'
				),
				'Zoom mode' => array(
					'disable-zoom',
					'always-show-zoom',
					'drag-mode',
					'move-on-click',
					'x',
					'y',
					'preserve-position',
					'fit-zoom-window'
				),
				'Hint' => array(
					'hint',
					'hint-text',
					'hint-position',
					'hint-opacity'
				)
			)
		);
		if(!$this->isPrestahop16x) {
			unset($map['blockbestsellers_home'], $map['blocknewproducts_home']);
		}
		return $map;
	}

}
