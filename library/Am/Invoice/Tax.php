<?php

/**
 * Tax plugins storage
 * @package Am_Invoice
 */
class Am_Plugins_Tax extends Am_Plugins
{
    // calculate tax
    /** @return array of calculators */
    function match(Invoice $invoice)
    {
        $di = $invoice->getDi();
        $ret = array();
        foreach ($this->getEnabled() as $id)
        {
            $obj = $this->get($id);
            $calcs = $obj->getCalculators($invoice);
            if ($calcs && !is_array($calcs))
                $calcs = array($calcs);
            if ($calcs)
                $ret = array_merge($ret, $calcs);
        }
        return $ret;
    }
    function getAvailable(){
        $result = array();
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, 'Am_Invoice_Tax'))
                $result[] = fromCamelCase(str_replace('Am_Invoice_Tax_', '', $class), '-');
        }        
        return $result;
    }
}

/**
 * Abstract tax plugin
 * @package Am_Invoice
 */
abstract class Am_Invoice_Tax extends Am_Pluggable_Base
{
    protected $_idPrefix = 'Am_Invoice_Tax_';
    protected $_configPrefix = 'tax.';
    protected $_alwaysAbsorb = false; //backward compatability (Am_Invoice_Tax_Gst)

    function initForm(HTML_QuickForm2_Container $form) {}

    /**
     * @param Invoice $invoice
     * @return double
     */
    function getRate(Invoice $invoice) {}

    // get calculators
    function getCalculators(Invoice $invoice)
    {
        $rate = $this->getRate($invoice);
        if ($rate > 0.0) {
            return ($this->getConfig('absorb') || $this->_alwaysAbsorb) ?
                new Am_Invoice_Calc_Tax_Absorb($this->getId(), $this->getConfig('title', $this->getTitle()), $this) :
                new Am_Invoice_Calc_Tax($this->getId(), $this->getConfig('title', $this->getTitle()), $this);
        }
    }
    protected function _beforeInitSetupForm()
    {
        $form = parent::_beforeInitSetupForm();
        $form->addText('title', array('class' => 'el-wide'))->setLabel(___('Tax Title'))->addRule('required');
        if (!$this->_alwaysAbsorb) {
            $form->addAdvCheckbox('absorb')
                ->setlabel(___('Catalog Prices Include Tax'));
        }
        return $form;
    }
}




class Am_Invoice_Tax_GlobalTax extends Am_Invoice_Tax
{
    public function getTitle() { return ___("Global Tax"); }
    public function getRate(Invoice $invoice)
    {
        return $this->getConfig('rate');
    }
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $gr = $form->addGroup()->setLabel(___("Tax Rate\nfor example 18.5 (no percent sign)"));
        $gr->addText('rate', array('size'=>5));
        $gr->addStatic()->setContent(' %');
    }
}

class Am_Invoice_Tax_Regional extends Am_Invoice_Tax
{
    public function getTitle()
    {
        return ___("Regional Tax");
    }

    public function getRate(Invoice $invoice)
    {
        $user = $invoice->getUser();
        if (!$user) return;
        $rate = null;
        foreach ((array)$this->getConfig('rate') as $t){
            if (!empty($t['zip']))
                if (!$this->compareZip($t['zip'], $user->get('zip')))
                    continue; // no match
            if (!empty($t['state']) && ($t['state'] == $user->get('state')) && ($t['country'] == $user->get('country')))
            {
                $rate = $t['tax_value'];
                break;
            }
            if (!$t['state'] && !empty($t['country']) && ($t['country'] == $user->get('country')))
            {
                $rate = $t['tax_value'];
                break;
            }
        }
        return $rate;
    }

    protected function compareZip($zipString, $zip)
    {
        $zip = trim($zip);
        foreach (preg_split('/[,;\s]+/', $zipString) as $s)
        {
            $s = trim($s);
            if (!strlen($s)) continue;
            if (strpos($s, '-'))
                list($range1, $range2) = explode('-', $s);
            else
                $range1 = $range2 = $s;
            if (($range1 <= $zip) && ($zip <= $range2))
            {
                return true;
            }
        }
        return false;
    }
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addElement(new Am_Form_Element_RegionalTaxes('rate'));
    }
}

class Am_Invoice_Tax_Vat extends Am_Invoice_Tax
{
    public function getTitle() { return ___("VAT"); }
    // will be transformed to code => title in constructor
    protected $countries = array(
        'AT', 'BE', 'BG', 'CY', 'HR', 'CZ', 'DE',
        'DK', 'EE', 'GR', 'ES', 'FI', 'FR',  // GR is known as EL for tax
        'GB', 'HU', 'IE', 'IT', 'LT', 'LU',
        'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SK', 'SI'
    );
    protected $euCountries = array();
    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $countryList = $di->countryTable->getOptions();
        foreach ($this->countries as $k => $c)
        {
            unset($this->countries[$k]);
            $this->countries[$c] = $countryList[$c];
        }
    }
    public function getRate(Invoice $invoice)
    {
        $u = $invoice->getUser();
        $id = is_null($u) ? false : $u->get('tax_id');
        if ($id && $this->getConfig('extempt_if_vat_number'))
        {
            // if that is a foreign customer
            if (strtoupper(substr($this->getConfig('my_id'), 0, 2)) != strtoupper(substr($id, 0, 2)))
                return null;
        }
        $country = $id ? substr($id, 0, 2) : ( is_null($u) ? false : $u->get('country'));
        if (!$country) $country = $this->getConfig('my_country');
        if ($country == $this->getConfig('my_country'))
            return $this->getConfig('local_rate');
        if(!$this->getConfig('add_vat_outside_eu') && !array_key_exists(strtoupper($country), $this->countries))
            return null;
        return $this->getConfig('rate.'.strtoupper($country), $this->getConfig('local_rate'));
    }
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSelect('my_country')
            ->setLabel(___('My Country'))
            ->loadOptions($this->countries)
            ->addRule('required');

        $form->addText('my_id')
            ->setLabel(___('VAT Id'))
            ->addRule('required');

        $gr = $form->addGroup()
            ->setLabel(___("Local VAT Rate\n" .
                "for example 18.5 (no percent sign)"));
        $gr->addText('local_rate', array('size' => 5, 'maxlength' => 5));
        $gr->addStatic()->setContent(' %');

        $form->addAdvCheckbox('extempt_if_vat_number')
            ->setLabel(___('Do not add VAT if a valid EU VAT Id entered by foreign customer'));

        $fs = $form->addGroup()
            ->setLabel(___("Tax Rates for Other Countries\n" .
                'in case of it is different with Local VAT Rate'));

        foreach ($this->countries as $k => $v) {
            $fs->addText('rate.' . $k, array('class'=>'vat-rate'));
        }

        $rate = $fs->addElement(new Am_Form_Element_VatRate('rate'));
        foreach ($this->countries as $k => $v) {
            $val = $this->getConfig('rate.' . $k, $this->getConfig('local_rate', ''));
            $attr = array(
                'data-label'=> $v . sprintf(' <input style="padding:0" type="text" onChange = "$(this).closest(\'div\').find(\'input[type=hidden]\').val($(this).val())"  name="" size="4" value="%s" /> %%', $val),
                'data-value' => $val
            );
            $rate->addOption($v, $k, $attr);
        }

        $rate->setJsOptions('{
            getOptionName : function (name, option) {
                return name.replace(/\[\]$/, "") + "___" + option.value;
            },
            getOptionValue : function (option) {
                return $(option).data("value");
            }
        }');
        $rate->setValue(array_keys(array_filter($this->getConfig('rate', array()))));

        $form->addScript()
            ->setScript(<<<CUT
$('.vat-rate').hide();
$('.vat-rate').val('');
CUT
            );
    }
    
    
    function onAdminWarnings(Am_Event $e){
        $e->addReturn("Important Information about EU VAT Changes: <a href='http://www.amember.com/docs/Configure_EU_VAT'>please read</a>");
    }
    
    function onGridUserInitForm(Am_Event_Grid $event)
    {
        $form = $event->getGrid()->getForm();
        $user = $event->getGrid()->getRecord();
        
        $address_fieldset  = $form->getElementById('address_info');
        
        $tax_id_el = new HTML_QuickForm2_Element_InputText('tax_id');
        $tax_id_el->setLabel(___('Tax Id'));
        
        $form->insertBefore($tax_id_el, $address_fieldset);
        
    }
    
}



class Am_Invoice_Tax_Vat2015 extends Am_Invoice_Tax
{
        protected $rates = array(
        'AT'=>20, 'BE' => 21, 'BG'=>20, 'CY'=>19, 'HR'=>25, 'CZ'=>21, 'DE'=>19,
        'DK'=>25, 'EE'=>20, 'GR'=>23, 'ES'=>21, 'FI'=>24, 'FR'=>20,  // GR is known as EL for tax 
        'GB'=>20, 'HU'=>27, 'IE'=>23, 'IM'=> 20, 'IT'=>22, 'LT'=>21, 'LU'=>17, 
        'LV'=>21, 'MT'=>18, 'NL'=>21, 'PL'=>23, 'PT'=>23, 'RO'=>24, 
        'SE'=>25, 'SK'=>20, 'SI'=>22
            );

    protected $countries = array();
    protected $countryLookupService;
    
    const INVOICE_IP = 'tax_invoice_ip';
    const INVOICE_IP_COUNTRY = 'tax_invoice_ip_country';
    const USER_REGISTRATION_IP  = 'tax_user_registration_ip';
    const USER_REGISTRATION_IP_COUNTRY  = 'tax_user_registration_ip_country';
    const USER_COUNTRY = 'tax_user_country';
    const SELF_VALIDATION_COUNTRY = 'tax_self_validation_country';
    const INVOICE_COUNTRY = 'tax_invoice_country';
    
    
    
    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $countryList = $di->countryTable->getOptions();
        foreach ($this->rates as $k=>$c)
        {
            if(!isset($countryList[$k])) continue;
            $this->countries[$k] = $countryList[$k];
        }
    }
    
    public function getTitle() { return ___("EU VAT (New Rules 2015)"); }
    
    
    function _initSetupForm(Am_Form_Setup $form)
    {
        $gr = $form->addGroup('')->setLabel(___("Electronically Supplied Service\n" .
            'Enable if ALL your products are electronic services.'));
        $gr->addAdvCheckbox('tax_digital');
        $gr->addHTML()->setHTML(<<<EOT
<div><a href="javascript:;" onclick="$('#tax_digital_example').toggle()" class="local">Examples of Electronic Service</a></div>
<div id="tax_digital_example" style="display:none">
<ul class="list">
 <li>Website supply, web-hosting, distance maintenance of programmes  and equipment;</li>
 <li>Supply of software and updating thereof;</li>
 <li>Supply of images, text and information and making available of databases;</li>
 <li>Supply of music, films and games, including games of chance and gambling games, and of
political, cultural, artistic, sporting, scientific and entertainment broadcast and events;</li>
 <li>Supply of distance teaching.</li>
</ul>
</div>
EOT
   );
        $fieldSet = $form->addFieldSet('maxmind', array('id' => 'maxmind'))->setLabel('Location Validation Settings');
        $fieldSet->addHTML(null, array('class'=>'no-label'))->setHTML(<<<EOT
<p>According to new EU VAT rules your are required to collect two pieces of non-conflicting evidence of customer's location country if you are selling Digital (Electronic Service) Products. These two pieces of evidence will be checked on each invoice which has Digital Product included:</p>
<ul class="list">
    <li>Address Contry (so make sure that you  have added Address info brick to signup form)</li>
    <li>IP Address Country</li>
</ul>
<p>In order to get country from customer's IP address, aMember uses MaxMind GeoIP2 service. Please signup <a href = "https://www.maxmind.com/en/geoip2-precision-country" target="_blank" class="link">here</a> in order to get MaxMind user ID and license key.</p>
           
EOT
   );
        $fieldSet->addAdvCheckbox('tax_location_validation')
            ->setLabel(___("Enable Location Validation\n" .
                'aMember will require two peices of location evidence before an invoice is created. ' .
                'Invoice that fials validation will be blocked and user will receive warning.'));

        $fieldSet->addAdvCheckbox('tax_location_validate_all')
            ->setLabel(___("Validate Location even if Invoice has no VAT \n" .
                           "Validate All New Invoices (even if invoice has no VAT)\n".
                           'If unchecked, location will be validated only when user selects country inside EU and only if VAT should be applied to invoice'
                ));
        
        $fieldSet->addAdvCheckbox('tax_location_validate_self')
            ->setLabel(___("Enable Self-Validation\n" .
                           "If validation failed, user will be able to confirm current location manually\n"
                ));
        
        $fieldSet->addText('tax_maxmind_user_id')->setLabel(___('MaxMind User ID'));
        $fieldSet->addText('tax_maxmind_license')->setLabel(___('MaxMind License'));
        
        $fieldSet = $form->addFieldSet('vat_id')->setLabel(___("Account Information"));
        $fieldSet->addSelect('my_country')
            ->setLabel(___('My Country'))
            ->loadOptions($this->countries)
            ->addRule('required');
        
        $fieldSet->addText('my_id')
            ->setLabel(___('VAT Id'))
            ->addRule('required');
        
        
        $fieldSet = $form->addFieldSet('numbering')->setLabel(___('Invoice numbering'));
        $fieldSet->addAdvCheckbox("sequential")->setLabel(___("Sequential Receipt# Numbering\n" . 
            "aMember still creates unique id for invoices, but it will\n" . 
            "generate PDF receipts for each payment that will be\n".
            "available in the member area for customers"));
        $form->setDefault('sequential', 1);
        $form->setDefault('tax_digital', 1);
        
        $fieldSet->addText('invoice_prefix')->setLabel(___("Receipt# Number Prefix\n" .
            'If you change prefix numbers will start over from 1'))->setValue('INV-');
        $fieldSet->addText('initial_invoice_number')->setLabel(___('Initial Receipt# Number'))->setValue(1);
        $fieldSet->addText('invoice_refund_prefix')->setLabel(___("Refund Receipt# Prefix\n" .
            'If you change prefix numbers will start over from 1'))->setValue('RFND-');
        $fieldSet->addText('initial_invoice_refund_number')->setLabel(___('Initial Receipt# Refund Number'))->setValue(1);
        
        
        
        $fs = $form->addFieldSet('rates')->setLabel(___('VAT Rates, %'));
        $rates = array_filter($this->getConfig('rates',array()));
        foreach($this->rates as $c=>$rate){
            if(!isset($this->countries[$c])) continue;
            $r = $fs->addText('rate.'.$c, 'size=3')->setLabel($this->countries[$c]);
            $r->setValue(!empty($rate[$c])?$rate[$c]:$this->rates[$c]);
        }
        
        
    }
    
    /**
     * 
     * @return Am_Invoice_CountryLookup_Abstract
     */
    function getCountryLookupService()
    {
        return $this->getDi()->countryLookup;
        
    }
    
    function hasDigitalProducts(Invoice $invoice){
        
        if($this->getConfig('tax_digital')) return true;
        
        foreach($invoice->getProducts() as $p){
            if($p->tax_group && $p->tax_digital) return true;
        }
    }
    
    public function getRate(Invoice $invoice, InvoiceItem $item = null)
    {
        $u = $invoice->getUser();
        $id = is_null($u) ? false : $u->get('tax_id');
        if ($id)
        {
            // if that is a foreign customer
            if (strtoupper(substr($this->getConfig('my_id'), 0, 2)) != strtoupper(substr($id, 0, 2)))
                return null;
        }
        $country = $this->hasDigitalProducts($invoice) ? ($id ? substr($id, 0, 2) : ( is_null($u) ? false : $u->get('country'))) : $country = $this->getConfig('my_country');
        
        if (!$country) $country = $this->getConfig('my_country');
        
        if ($country == $this->getConfig('my_country'))
            return $this->getRatePerProduct($country, $item);
        
        if(!array_key_exists(strtoupper($country), $this->countries))
            return null;
        
        return $this->getRatePerProduct($country, $item);
    }
    
    function getRatePerProduct($country, InvoiceItem $item=null){
        $country = strtoupper($country);
        if(!empty($item) && $item->item_type == 'product')
        {
            $product = $item->tryLoadProduct();
            $rates = unserialize($product->data()->getBlob('vat_eu_rate'));
            $customRate = (is_array($rates) && isset($rates[$country])&& !empty($rates[$country])) ? $rates[$country] : null;
        }else
            $customRate = null; 
        return (!is_null($customRate) ? $customRate : $this->getConfig('rate.'.strtoupper($country), $this->rates[strtoupper($country)]));
    }
    

    
    function onGridProductInitForm(Am_Event $event)
    {
        $form = $event->getGrid()->getForm();
        $fs = $form->addAdvFieldSet("custom_vat_rates")->setLabel('Custom EU Vat Rates');
        foreach($this->rates as $c=>$rate){
            if(isset($this->countries[$c])) 
                $r = $fs->addText('_rate['.$c.']', 'size=3')->setLabel($this->countries[$c]);
        }
        
        if(!$this->getConfig('tax_digital'))
        {
            $fieldSet = $form->getElementById('billing');
            
            $gr = $fieldSet->addGroup('')->setLabel(array(___('Electronically Supplied Service'), ___('Enable if your product is an electronic service.')));
            $gr->addAdvCheckbox('tax_digital');
            $gr->addHTML()->setHTML(<<<EOT
<div><a href="javascript:;" onclick="$('#tax_digital_example').toggle()" class="local">Examples of Electronic Service</a></div>
<div id="tax_digital_example" style="display:none">
<ul class="list">
 <li>Website supply, web-hosting, distance maintenance of programmes  and equipment;</li>
 <li>Supply of software and updating thereof;</li>
 <li>Supply of images, text and information and making available of databases;</li>
 <li>Supply of music, films and games, including games of chance and gambling games, and of
political, cultural, artistic, sporting, scientific and entertainment broadcast and events;</li>
 <li>Supply of distance teaching.</li>
</ul>
</div>
EOT
    );
            
            
        }
        
    }

    function onGridProductBeforeSave(Am_Event $event)
    {
        $product = $event->getGrid()->getRecord();
        $val = $event->getGrid()->getForm()->getValue();
        $product->data()->setBlob('vat_eu_rate', serialize($val['_rate']));
    }

    function onGridProductValuesToForm(Am_Event $event)
    {
        $args = $event->getArgs();
        $values = $args[0];
        $product = $event->getGrid()->getRecord();
        if ($rate = unserialize($product->data()->getBlob('vat_eu_rate')))
        {
            $values['_rate'] = $rate;
            $event->setArg(0, $values);
        }
    }
    
    
    function onInvoiceValidate(Am_Event $event)
    {
        $invoice = $event->getInvoice();
        $user = $invoice->getUser();

        if($user->get('tax_id')) return; // User already has specified his tax ID, do not validate; 
        
        // Disable validation for aMember CP; 
        if(defined('AM_ADMIN') && AM_ADMIN) 
            return;
        
        if(
            (($invoice->first_tax>0) || ($invoice->second_tax>0) || $this->getConfig('tax_location_validate_all')) 
            && $this->getConfig('tax_location_validation') 
            && $this->hasDigitalProducts($invoice)
            )
        {
            if(!$this->locationIsValid($invoice))
                $event->setReturn(sprintf(___("Location validation failed.") .
                                          ___("Registration country and your IP address country doesn't match. ")
                                ));

        }
        
    }
    
    function locationIsValid(Invoice $invoice)
    {
            $evidence = array();
            
            $user = $invoice->getUser();
            
            $invoice->data()->set(self::INVOICE_IP, $invoice_ip = $this->getDi()->request->getClientIp());
            $invoice->data()->set(self::USER_REGISTRATION_IP, $user->remote_addr);
            $invoice->data()->set(self::USER_COUNTRY, $user_country = $user->get('country'));
            if(!$invoice->data()->get(self::INVOICE_COUNTRY))
                $invoice->data()->set(self::INVOICE_COUNTRY, $user_country);
            
            if($this->getConfig('tax_location_validate_self'))
                $invoice->data()->set(self::SELF_VALIDATION_COUNTRY, $evidence[] = $user->data()->get(self::SELF_VALIDATION_COUNTRY));

            try
            {
                $invoice->data()->set(self::INVOICE_IP_COUNTRY, $evidence[] = $this->getCountryLookupService()->getCountryCodeByIp($invoice_ip));
            }
            catch(Exception $e)
            {
                $this->getDi()->errorLogTable->logException($e);
            }
            
            
            try
            {
                $invoice->data()->set(self::USER_REGISTRATION_IP_COUNTRY, $evidence[] = $this->getCountryLookupService()->getCountryCodeByIp($user->remote_addr));
            }
            catch(Exception $e)
            {
                $this->getDi()->errorLogTable->logException($e);
            }

            
            if(!in_array($user_country, $evidence))
                return false; 
            
            return true;
        
    }
    
    function onSetDisplayInvoicePaymentId(Am_Event $e)
    {
        if($this->getConfig('sequential'))
            $this->setSequentialNumber($e,$this->getConfig('invoice_prefix','INV-'), $this->getConfig('initial_invoice_number', 1));
        
    }
    function onSetDisplayInvoiceRefundId(Am_Event $e)
    {
        if($this->getConfig('sequential'))
            $this->setSequentialNumber($e,$this->getConfig('invoice_refund_prefix','RFND-'), $this->getConfig('initial_invoice_refund_number', 1));
    }
    
    function setSequentialNumber(Am_Event $e, $prefix, $default)
    {
        $numbers = $this->getDi()->store->getBlob('invoice_sequential_numbers');
        
        if(empty($numbers))
            $numbers = array();
        else
            $numbers = @unserialize($numbers);
        
        if(empty($numbers[$prefix]))
            $numbers[$prefix] = $default;
        else
            $numbers[$prefix]++;
        
        $this->getDi()->store->setBlob('invoice_sequential_numbers', serialize($numbers));
        $e->setReturn($prefix.$numbers[$prefix]);
    }
    
    function getReadme(){
        return <<<EOT
Latest version of plugin readme available here: <a href='http://www.amember.com/docs/Configure_EU_VAT'>http://www.amember.com/docs/Configure_EU_VAT</a>
EOT;
    }
    
    function onValidateSavedForm(Am_Event $e){
        if(!$this->getConfig('tax_location_validate_self')) return;
        
        $form = $e->getForm();
        $el = $form->getElementById('f_country');
        if(!empty($el))
        {
            $user_country = $el->getValue();
            try{
                // Form has country element. Now we need to check user's choice and validate it agains IP country. 
                $current_country = $this->getDi()->countryLookup->getCountryCodeByIp($this->getDi()->request->getClientIp());
            }catch(Exception $e){
                // Nothing to do;
                $error = $e->getMessage();
            }
            if(empty($current_country) || ($current_country !== $user_country)){
                // Need to add self-validation element;
                if(!($sve = $form->getElementById('tax_self_validation')) || !$sve->getValue()){
                    $sve = new Am_Form_Element_AdvCheckbox('tax_self_validation');
                    $sve->setLabel(array(___('Confirm Billing Country')))
                        ->setId('tax_self_validation')
                        ->setContent(sprintf(   
                            "<span class='error' style='display:inline;'><b>".___("I confirm I'm based in %s")."</b></span>",
                            $this->getDi()->countryTable->getTitleByCode($user_country)));

                    foreach($form as $el1){
                        $form->insertBefore($sve, $el1);
                        break;
                    }
                    if($sve->getValue()){
                        // Confirmed; 
                        $this->getDi()->session->tax_self_validation_country = $el->getValue();
                    }else{
                        $form->setError(
                                        ___("Please confirm your billing address manually") . "<br/>" .
                                        ___("It looks like you are not at home right now.") . "<br/>" . 
                                        ___("In order to comply with EU VAT Rules we need you to confirm your billing country.") 
                            );
                    }
                }else{
                    // Element already added;  nothing to do
                }
            }
            
        }
        
    }
    
    function setSelfValidationCountry(User $user){
        
        if($country = $this->getDi()->session->tax_self_validation_country){
            $user->data()->set(self::SELF_VALIDATION_COUNTRY, $country);
            $this->getDi()->session->tax_self_validation_country = null;
        }
        
    }
    function onUserBeforeUpdate(Am_Event $e){
        $this->setSelfValidationCountry($e->getUser());
    }
    
    function onUserBeforeInsert(Am_Event $e){
        $this->setSelfValidationCountry($e->getUser());
    }
    
    function onAdminMenu(Am_Event $e){
        $menu = $e->getMenu();
        $reports = $menu->findOneBy('id', 'reports');
        $reports->addPage(               
            array(
                    'id' => 'reports-vat',
                    'controller' => 'admin-vat-report',
                    'label' => ___('EU VAT Report'),
                    'resource' => Am_Auth_Admin::PERM_REPORT,
                )
        );
        
    }
    function onGridUserInitForm(Am_Event_Grid $event)
    {
        $form = $event->getGrid()->getForm();
        $user = $event->getGrid()->getRecord();
        
        $address_fieldset  = $form->getElementById('address_info');
        
        $tax_id_el = new HTML_QuickForm2_Element_InputText('tax_id');
        $tax_id_el->setLabel(___('Tax Id'));
        
        $form->insertBefore($tax_id_el, $address_fieldset);
        
    }
}

class Am_Invoice_Tax_Gst extends Am_Invoice_Tax_Regional
{
    protected $_alwaysAbsorb = true;

    public function getTitle()
    {
        return ___("GST (Inclusive Tax)");
    }
}
