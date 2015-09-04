<?php

class Am_Plugin_SubscriptionLimit extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '4.6.3';

    function init()
    {
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('subscription_limit', ___('Subscription limit'), ___('limit amount of subscription for this product, keep empty if you do not want to limit amount of subscriptions')));
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('subscription_user_limit', ___('Subscription limit for each user'), ___('limit amount of subscription for this product per user, keep empty if you do not want to limit amount of subscriptions')));
        
    }

    function onInvoiceBeforePayment(Am_Event $event)
    {
        /* @var $invoice Invoice */
        $invoice = $event->getInvoice();
        $user = $invoice->getUser();

        foreach ($invoice->getItems() as $item)
        {
            if ($item->item_type != 'product') continue;
            $product = $this->getDi()->productTable->load($item->item_id);
            if (($limit = $product->data()->get('subscription_limit')) &&
                $limit < $item->qty)
            {
                throw new Am_Exception_InputError(sprintf('There is not such amount (%d) of product %s', $item->qty, $item->item_title));
            }
            
            $count  = $this->getDI()->db->selectCell("
                SELECT SUM(ii.qty) 
                FROM ?_invoice_item ii LEFT JOIN ?_invoice i ON ii.invoice_id = i.invoice_id 
                WHERE i.user_id = ? and ii.item_id=? and i.status<>0
                ", $user->pk(), $product->pk());
            if (($limit = $product->data()->get('subscription_user_limit')) &&
                $limit < ($item->qty + $count))
            {
                throw new Am_Exception_InputError(sprintf('There is not such amount (%d) of product %s you can purchase only %s items.', $item->qty, $item->item_title, $limit));
            }
            
            
        }
    }

    function onInvoiceStarted(Am_Event_InvoiceStarted $event)
    {
        $invoice = $event->getInvoice();
        foreach ($invoice->getItems() as $item)
        {
            if ($item->item_type != 'product') continue;
            $product = $this->getDi()->productTable->load($item->item_id);

            if ($limit = $product->data()->get('subscription_limit'))
            {
                $limit -= $item->qty;
                $product->data()->set('subscription_limit', $limit);
                if (!$limit)
                {
                    $product->is_disabled = 1;
                }
                $product->save();
            }
        }
    }

    function getReadme()
    {
        return <<<CUT
This plugin allows you to limit amount of available
subscription for specific product. The product will
be disabled in case of limit reached.

You can set up limit in product settings
aMember CP -> Products -> Manage Products -> Edit (Subscription limit)
CUT;
    }
    
    function onGridProductInitGrid(Am_Event_Grid $event)
    {
        $grid = $event->getGrid();
        $grid->addField(new Am_Grid_Field('subscription_limit', ___('Limit'), false))->setRenderFunction(array($this, 'renderLimit'));
    }

    function renderLimit(Product $product)
    {
        return '<td align="center">' . ( ($limit = $product->data()->get('subscription_limit')) ? $limit : '&ndash;')  . '</td>';
    }

}