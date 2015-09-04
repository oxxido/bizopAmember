<?php
/**
 * @table paysystems
 * @id robokassa
 * @title Robokassas
 * @visible_link http://robokassa.ru
 * @country RU
 * @recurring none
 * @logo_url robokassa.png
 */
class Am_Paysystem_Robokassa extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '4.6.3';

    protected $defaultTitle = 'Robokassa';
    protected $defaultDescription = 'On-line Payments';
    
    const LIVE_URL = "https://auth.robokassa.ru/Merchant/Index.aspx";
    const TEST_URL = "http://test.robokassa.ru/Index.aspx";

    function getSupportedCurrencies()
    {
        return array('RUB');
    }
    public 
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_login')->setLabel('Merchant Login');
        $form->addText('merchant_pass1')->setLabel(array('Password #1', 'From shop technical preferences'));
        $form->addText('merchant_pass2')->setLabel(array('Password #2', 'From shop technical preferences'));
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
        $form->addSelect('language', '', array('options' => array('en'=>'English', 'ru'=>'Russian')))->setLabel('Interface Language');
    }
    public
        function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::TEST_URL : self::LIVE_URL);
        $vars = array(
            'MrchLogin' =>$this->getConfig('merchant_login'),
            'OutSum'=> $invoice->first_total,
            'InvId'=> $invoice->invoice_id,
            'Desc' => $invoice->getLineDescription(),
            'Culture' => $this->getConfig('language', 'en')
        );
        
        $vars['SignatureValue'] = $this->getSignature($vars, $this->getConfig('merchant_pass1'));
        foreach($vars as $k=>$v){
            $a->addParam($k,$v);
        }
        $result->setAction($a);
    }
    
    
    function getSignature($vars, $pass){
        $md5 = md5($s = sprintf("%s:%s:%s:%s", $vars['MrchLogin'], $vars['OutSum'], $vars['InvId'], $pass));
        return $md5;
        
    }
    function getIncomingSignature($vars, $pass){
        $md5 = md5($s = sprintf("%s:%s:%s", $vars['OutSum'], $vars['InvId'], $pass));
        return $md5;
        
    }
    

    public
        function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Robokassa($this, $request, $response, $invokeArgs);
    }
    
    public 
        function createThanksTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Robokassa_Thanks($this, $request, $response, $invokeArgs);
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }   
    
    function getReadme(){
        return <<<CUT
In shop Technical Preferences set: 

Result URL: %root_url%/payment/robokassa/ipn
Method of sending data to Result Url : POST

Success Url: %root_url%/payment/robokassa/thanks
Method of sending data to Success Url: GET

Fail URL: %root_url%/cancel
Method of sending data to Fail Url: GET
        
CUT;
    }
}

class Am_Paysystem_Transaction_Robokassa extends Am_Paysystem_Transaction_Incoming
{
    public
        function getUniqId()
    {
        return $this->invoice->public_id;
    }

    public
        function validateSource()
    {
        if(strtoupper($this->getPlugin()->getIncomingSignature($this->request->getParams(), $this->getPlugin()->getConfig('merchant_pass2'))) != $this->request->getParam('SignatureValue'))
            return false;
        
        return true;
    }

    public
        function validateStatus()
    {
        return true; 
    }

    public
        function validateTerms()
    {
        return $this->request->getParam('OutSum') == $this->invoice->first_total;
    }    
    
    function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->load($this->request->getFiltered('InvId'));
        return $invoice->public_id;
    }
    function processValidated()
    {
        parent::processValidated();
        print "OK".$this->invoice->invoice_id;
    }
}

class Am_Paysystem_Transaction_Robokassa_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public
        function getUniqId()
    {
        return $this->invoice->public_id;
    }

    public
        function validateSource()
    {
        if($this->getPlugin()->getIncomingSignature($this->request->getParams(), $this->getPlugin()->getConfig('merchant_pass1')) != $this->request->getParam('SignatureValue'))
            return false;
        
        return true;
        
    }

    public
        function validateStatus()
    {
        return true; 
    }

    public
        function validateTerms()
    {
        return $this->request->getParam('OutSum') == $this->invoice->first_total;
    }    
    function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->load($this->request->getFiltered('InvId'));
        return $invoice->public_id;
    }
    
}