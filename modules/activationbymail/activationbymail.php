<?php

/**
  * activationbymail
  * @category tools
  *
  * @author Dominik Cebula dominikcebula@gmail.com
  * @copyright Dominik Cebula dominikcebula@gmail.com
  * @license GNU_GPL_v2
  * @version 1.0
  */
class activationbymail extends Module {

    public function __construct() {
        $this->name='activationbymail';
        $this->version='1.0';
        $this->tab='Tools';

        parent::__construct();

        $this->displayName=$this->l('Account activation by e-mail');
        $this->description=$this->l('This module allows your shop to validate e-mails by sending activation links');
    }

    public function install() {
        if (parent::install()
                && $this->registerHook('createAccount')
                && Db::getInstance()->Execute('alter table '._DB_PREFIX_.'customer add activation_link char(32)'))
            return true;
        else
            return false;
    }

    public function uninstall() {
        if (parent::uninstall()
                && $this->unregisterHook('createAccount')
                && Db::getInstance()->Execute('alter table '._DB_PREFIX_.'customer drop activation_link'))
            return true;
        else
            return false;
    }

    public function hookcreateAccount($req) {
        global $cookie;
        $id_lang=$cookie->id_lang;
        $cookie->logout();
        $cookie->id_lang=$id_lang;
        $cookie->write();

        $link=md5(uniqid(rand(), true));

        $sql=sprintf("update %scustomer set active=0, activation_link='%s' where id_customer=%d",
                _DB_PREFIX_,
                $link,
                $req['newCustomer']->id
        );
        Db::getInstance()->Execute($sql);

        $customer=new Customer($req['newCustomer']->id);
        $customer->getFields();

        Mail::Send($id_lang,
                'account_activation',
                $this->l('Account activation'),
                array('{firstname}' => $customer->firstname, '{lastname}' => $customer->lastname, '{email}' => $customer->email,'{link}' => $link),
                $customer->email,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                'modules/activationbymail/mails/');
        Tools::redirect('modules/activationbymail/info.php');
    }

    public function execInfo() {
        global $smarty;
        return $this->display(__FILE__, 'info.tpl');
    }

    private function isMD5($str) {
        for ($i=0;$i<strlen($str);$i++)
            if (!(($str[$i]>='a' && $str[$i]<='z') || ($str[$i]>='0' && $str[$i]<='9')))
                return false;
        return true;
    }

    public function execActivation() {
        $link=Tools::getValue('link');
        if ($this->isMD5($link))
            Db::getInstance()->Execute('update '._DB_PREFIX_.'customer set active=1 where activation_link="'.$link.'"');
        Tools::redirect('my-account.php');
    }
}

?>
