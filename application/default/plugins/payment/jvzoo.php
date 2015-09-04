<?php
/**
 * @table paysystems
 * @id jvzoo
 * @title JVZoo
 * @visible_link http://jvzoo.com
 * @logo_url jvzoo.png
 * @recurring paysystem
 */
//http://support.jvzoo.com/Knowledgebase/Article/View/17/2/jvzipn

class Am_Paysystem_Jvzoo extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '4.6.3';

    public $domain = "";
    protected $defaultTitle = "JVZoo";
    protected $defaultDescription = "";

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'jvzoo_prod_item',
                "JVZoo product number",
                "1-5 Characters"
                , array(/* ,'required' */)
        ));
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("secret", array('size' => 40))
            ->setLabel("JVZoo Secret Key");
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do. 
    }

    public function createTransaction(Am_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Jvzoo($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
<b>JVZoo integration</b>
JVZIPN URL for your products in JVZoo should be set to: <b><i>$url</i></b>
CUT;
    }

}

class Am_Paysystem_Transaction_Jvzoo extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "SALE";
    const BILL = "BILL";

    // refund
    const RFND = "RFND";
    const CGBK = "CGBK";
    const INSF = "INSF";

    // cancel
    const CANCEL_REBILL = "CANCEL-REBILL";

    // uncancel
    const UNCANCEL_REBILL = "UNCANCEL-REBILL";

    protected $_autoCreateMap = array(
        'name' => 'ccustname',
        'email' => 'ccustemail',
        'state' => 'ccuststate',
        'country' => 'ccustcc',
        'user_external_id' => 'ccustemail',
        'invoice_external_id' => 'ctransreceipt',
    );

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('cproditem');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('jvzoo_prod_item', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function getReceiptId()
    {
        switch ($this->request->get('ctransaction'))
        {
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                return $this->request->get('ctransreceipt').'-'.$this->request->get('ctransaction');
                break;
            default :
                return $this->request->get('ctransreceipt');
        }
        
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('ctransamount'));
    }

    public function getUniqId()
    {
        return @$this->request->get('ctransreceipt');
    }

    public function validateSource()
    {
        $ipnFields = $this->request->getPost();
        unset($ipnFields['cverify']);
        ksort($ipnFields);
        $pop = implode('|', $ipnFields) . '|' . $this->getPlugin()->getConfig('secret');
        if (function_exists('mb_convert_encoding'))
            $pop = mb_convert_encoding($pop, "UTF-8");
        $calcedVerify = strtoupper(substr(sha1($pop), 0, 8));
        return $this->request->get('cverify') == $calcedVerify;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->request->get('ctransaction'))
        {
            //payment
            case Am_Paysystem_Transaction_Jvzoo::SALE:
            case Am_Paysystem_Transaction_Jvzoo::BILL:
                $this->invoice->addPayment($this);
                break;
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                //$this->invoice->stopAccess($this);
                break;
            //cancel
            case Am_Paysystem_Transaction_Jvzoo::CANCEL_REBILL:
                $this->invoice->setCancelled(true);
                break;
            //un cancel
            case Am_Paysystem_Transaction_Jvzoo::UNCANCEL_REBILL:
                $this->invoice->setCancelled(false);
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->request->get('ctransreceipt');
    }

}