<?php

/**
 * @table paysystems
 * @id redsys
 * @title Redsys
 * @visible_link http://www.redsys.es
 * @country ES
 * @recurring none
 * @logo_url redsys.png
 */
class Am_Paysystem_Redsys extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '4.6.3';

    const LIVE_URL = 'https://sis.redsys.es/sis/realizarPago';
    const SANDBOX_URL = 'https://sis-t.redsys.es:25443/sis/realizarPago';

    protected $defaultTitle = 'Redsys';
    protected $defaultDescription = 'Pay by Redsys';

    public function getSupportedCurrencies()
    {
        return array(
            'EUR', 'USD', 'GBP', 'JPY', 'ARS', 'CAD',
            'CLP', 'COP', 'INR', 'MXN', 'PEN', 'CHF',
            'BRL', 'VEF', 'TRY'
        );
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('code')->setLabel('Merchant Code (FUC)');
        $form->addText('terminal')->setLabel('Terminal');
        $form->addPassword('secret')->setLabel('Secret Key (CLAVE SECRETA)');
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox (Testing) Account?");
    }

    public function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        $a = new Am_Paysystem_Action_Form($this->host());

        $vars = array(
            'Ds_Merchant_Amount' => $invoice->first_total * 100,
            'Ds_Merchant_Order' => $invoice->public_id,
            'Ds_Merchant_MerchantCode' => $this->getConfig('code'),
            'Ds_Merchant_Currency' => Am_Currency::getNumericCode($invoice->currency),
            'Ds_Merchant_TransactionType' => 0,
            'Ds_Merchant_MerchantURL' => $this->getPluginUrl('ipn')
        );

        foreach ($vars as $k => $v) {
            $a->$k = $v;
        }

        $a->Ds_Merchant_MerchantSignature = strtoupper(sha1(implode('', $vars) . $this->getConfig('secret')));
        $a->Ds_Merchant_Terminal = $this->getConfig('terminal');
        $a->Ds_Merchant_ProductDescription = $invoice->getLineDescription();
        $a->Ds_Merchant_UrlOK = $this->getReturnUrl();
        $a->Ds_Merchant_UrlKO = $this->getCancelUrl();
        $a->Ds_Merchant_MerchantName = $this->getDi()->config->get('site_title');

        $result->setAction($a);
    }

    public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Redsys($this, $request, $response, $invokeArgs);
    }

    function host()
    {
        return $this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
    }

}

class Am_Paysystem_Transaction_Redsys extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->getParam('Ds_AuthorisationCode');
    }

    public function findInvoiceId()
    {
        return $this->request->getParam('Ds_Order');
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
195.76.9.187
195.76.9.222
CUT
        );

        $msg = '';
        foreach (array('Ds_Amount', 'Ds_Order',
        'Ds_MerchantCode', 'Ds_Currency', 'Ds_Response') as $key) {

            $msg .= $this->request->getParam($key);
        }

        $digest = strtoupper(sha1($msg . $this->plugin->getConfig('secret')));
        return $digest == $this->request->getParam('Ds_Signature');
    }

    public function validateStatus()
    {
        return substr($this->request->getParam('Ds_Response'), 0, 2) == '00';
    }

    public function validateTerms()
    {
        return ($this->request->getParam('Ds_Amount') / 100) == $this->invoice->first_total &&
            $this->request->getParam('Ds_Currency') == Am_Currency::getNumericCode($this->invoice->currency);
    }

}