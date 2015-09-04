<?php

class Am_Plugin_GoogleAnalytics extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '4.6.3';
    const TRACKED_DATA_KEY = 'google-analytics-done';

    protected $id;
    protected $done = false;
    public function __construct(Am_Di $di, array $config)
    {
        $this->id = $di->config->get('google_analytics');
        parent::__construct($di, $config);
    }
    public function isConfigured()
    {
        return !empty($this->id);
    }
    function onSetupForms(Am_Event_SetupForms $forms)
    {
        $form = new Am_Form_Setup('google_analytics');
        $form->setTitle("Google Analytics");
        $forms->addForm($form);
        $form->addElement('text', 'google_analytics')
             ->setLabel(array('Google Analytics Account ID', 'To enable automatic sales and hits tracking with GA,
             enter Google Analytics cAccount ID into this field.
             <a href=\'http://www.google.com/support/googleanalytics/bin/answer.py?answer=55603\' target=_blank>Where can I find my tracking ID?</a>
             The tracking ID will look like <i>UA-1231231-1</i>.
             Please note - this tracking is only for pages displayed by aMember,
             pages that are just protected by aMember, cannot be tracked.
             Use '.
             '<a href="http://www.google.com/support/googleanalytics/bin/search.py?query=how+to+add+tracking&ctx=en%3Asearchbox" target=_blank>GA instructions</a>
             how to add tracking code to your own pages.
             '));
        $form->addAdvCheckbox("google_analytics_only_sales_code")
            ->setLabel(array("Include only sales code", "Enable this if you already have tracking code in template"));
        $form->addAdvCheckbox("google_analytics_track_free_signups")
            ->setLabel(array("Track free signups"));

        $form->addSelect("analytics_version")
            ->setLabel("Analytics Version")
            ->loadOptions(array(
                'google' => 'Google Analytics',
                'universal' => 'Universal Analytics',
            ));
    }
    function onAfterRender(Am_Event_AfterRender $event)
    {
        if ($this->done) return;
        if (preg_match('/thanks\.phtml$/', $event->getTemplateName()) && $event->getView()->invoice && $event->getView()->payment)
        {
            $this->done += $event->replace("|</body>|i", $this->getHeader() .
                    $this->getSaleCode($event->getView()->invoice, $event->getView()->payment) . "</body>", 1);
            if ($this->done) {
                $payment = $event->getView()->payment;
                $payment->data()->set(self::TRACKED_DATA_KEY, 1);
                $payment->save();
            }
        }
        elseif (preg_match('/signup\/signup.*\.phtml$/', $event->getTemplateName()))
        {
            $this->done += $event->replace("|</body>|i", $this->getHeader() .
                    $this->getTrackingCode(). $this->getSignupCode(). "</body>", 1);
        } else {
            if ($user_id = $this->getDi()->auth->getUserId()) {
                $payments = $this->getDi()->invoicePaymentTable->findBy(array(
                    'user_id' => $user_id,
                    'dattm' => '>' . sqlTime('-5 days')
                ));
                foreach ($payments as $payment) {
                    if ($payment->data()->get(self::TRACKED_DATA_KEY)) continue;

                    $this->done += $event->replace("|</body>|i", $this->getHeader() .
                        $this->getSaleCode($payment->getInvoice(), $payment) . "</body>", 1);
                    if ($this->done) {
                        $payment->data()->set(self::TRACKED_DATA_KEY, 1);
                        $payment->save();
                    }
                    break;
                }
            }

            if (!$this->done && !(defined('AM_ADMIN') && AM_ADMIN) && !$this->getDi()->config->get("google_analytics_only_sales_code")) {
                $this->done += $event->replace("|</body>|i", $this->getHeader() . $this->getTrackingCode() . "</body>", 1);
            }
        }
    }
    function getTrackingCode()
    {
        if($this->getDi()->config->get('analytics_version', 'google') == 'universal')
        {
            return <<<CUT
<script type="text/javascript">
    ga('create', '{$this->id}', 'auto');
    ga('send', 'pageview');
</script>
<!-- end of GA code -->
    
CUT;
        }

        return <<<CUT

<script type="text/javascript">
if (typeof(_gaq)=='object') { // sometimes google-analytics can be blocked and we will avoid error
    _gaq.push(['_setAccount', '{$this->id}']);
    _gaq.push(['_trackPageview']);
}
</script>
<!-- end of GA code -->

CUT;
    }
    function getSignupCode()
    {
    }
    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        if($this->getDi()->config->get('analytics_version', 'google') == 'universal')
        {
            $out = <<<CUT
<script type="text/javascript">
    ga('create', '{$this->id}', 'auto');
    ga('send', 'pageview');
</script>
CUT;
        } else
        {
            $out = <<<CUT

<script type="text/javascript">
if (typeof(_gaq)=='object') { // sometimes google-analytics can be blocked and we will avoid error
    _gaq.push(['_setAccount', '{$this->id}']);
    _gaq.push(['_trackPageview']);
}
</script>
CUT;
        }
        if (empty($payment->amount) && !$this->getDi()->config->get('google_analytics_track_free_signups')) {
            return $out;
        } elseif (empty($payment->amount)) {
            $a = array(
                $invoice->public_id,
                $this->getDi()->config->get('site_title'),
                0,
                0,
                0,
                $invoice->getCity(),
                $invoice->getState(),
                $invoice->getCountry(),
            );
        } else {
            $a = array(
                $payment->transaction_id,
                $this->getDi()->config->get('site_title'),
                $payment->amount - $payment->tax - $payment->shipping,
                (float)$payment->tax,
                (float)$payment->shipping,
                $invoice->getCity(),
                $invoice->getState(),
                $invoice->getCountry(),
            );
        }
        $a = implode(",\n", array_map('json_encode', $a));
        $items = "";
        foreach ($invoice->getItems() as $item)
        {
            if($this->getDi()->config->get('analytics_version', 'google') == 'universal')
            {
                $it = json_encode(array(
                    'id' => $payment->transaction_id,
                    'name' => $item->item_title,
                    'sku' => $item->item_id,
                    'price' => $item->first_total,
                    'quantity' => $item->qty,
                ));
                $items .= "ga('ecommerce:addItem', $it);\n";
            } else
            {
                $items .= "['_addItem', '$payment->transaction_id', '$item->item_id', '$item->item_title','', $item->first_total, $item->qty],";
            }

        }
        if($this->getDi()->config->get('analytics_version', 'google') == 'universal')
        {
            $tr = json_encode(array(
                'id' => $payment->transaction_id,
                'affiliation' => $this->getDi()->config->get("site_title"),
                'revenue' => empty($payment->amount) ? 0 : ($payment->amount - $payment->tax - $payment->shipping),
                'shipping' => empty($payment->amount) ? 0 : $payment->shipping,
                'tax' => empty($payment->amount) ? 0 : $payment->tax,
            ));
            return $out . <<<CUT
<script type="text/javascript">
    ga('require', 'ecommerce');
    ga('ecommerce:addTransaction', $tr);
    $items
    ga('ecommerce:send');
</script>
<!-- end of GA code -->
CUT;
        }

        return $out . <<<CUT
<script type="text/javascript">
if (typeof(_gaq)=='object') { // sometimes google-analytics can be blocked and we will avoid error
    _gaq.push(
        ['_addTrans', $a],
        $items
        ['_trackTrans']
    );
}
</script>
<!-- end of GA code -->
CUT;
    }
    function getHeader()
    {
        if($this->getDi()->config->get('analytics_version', 'google') == 'universal')
        {
            return <<<CUT

<!-- start of GA code -->
<script type="text/javascript">
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','//www.google-analytics.com/analytics.js','ga');
</script>
CUT;
        }

        return <<<CUT

<!-- start of GA code -->
<script type="text/javascript">
    var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
    document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
</script>
CUT;
    }
}
