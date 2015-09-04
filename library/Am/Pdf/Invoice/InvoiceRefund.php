<?php

class Am_Pdf_Invoice_InvoiceRefund extends Am_Pdf_Invoice_Abstract
{

    /**
     *
     * @var InvoiceRefund
     */
    protected
        $payment;

    /**
     *
     * @var InvoicePayment
     */
    protected
        $origPayment;

    function __construct(InvoiceRefund $refund)
    {
        $this->invoice = $refund->getInvoice();
        $this->payment = $refund;
    }

    public
        function render()
    {
        $invoice = $this->invoice;
        $payment = $this->payment;
        $user = $invoice->getUser();

        $pdf = $this->createPdfTemplate();

        $event = new Am_Event(Am_Event::PDF_INVOICE_BEFORE_RENDER, array(
            'amPdfInvoice' => $this,
            'pdf' => $pdf,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));
        $event->setReturn(false);
        $this->getDi()->hook->call($event);

        // If event processing already rendered the Pdf.
        if ($event->getReturn() === true)
        {
            return $pdf->render();
        }

        $width_num = 30;
        $width_qty = 40;
        $width_price = 80;
        $width_total = 120;

        $padd = 20;
        $left = $padd;
        $right = $this->getPaperWidth() - $padd;

        $fontH = $this->getFontRegular();
        $fontHB = $this->getFontBold();

        $styleBold = array(
            'font' => array(
                'face' => $fontHB,
                'size' => 10
        ));

        $page = new Am_Pdf_Page_Decorator($pdf->pages[0]);
        $page->setFont($fontH, 10);

        $pointer = $this->getPointer();
        $pointerL = $pointerR = $pointer;

        $leftCol = new stdClass();
        $leftCol->invoiceNumber = ___('Corrective Invoice Number: ') . $payment->getDisplayInvoiceId();
        $leftCol->recInvoiceNumber = ___('Rectifies Invoice Number: ') . $this->getOrigPayment()->getDisplayInvoiceId();
        $leftCol->date = ___('Date: ') . amDate($payment->dattm);
        if ($user->tax_id)
        {
            $leftCol->taxId = ___('EU VAT ID: ') . $user->tax_id;
        }

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_COL_LEFT, array(
            'col' => $leftCol,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));

        foreach ($leftCol as $line)
        {
            $page->drawText($line, $left, $pointerL);
            $page->nl($pointerL);
        }

        $rightCol = new stdClass();
        $rightCol->name = $invoice->getName();
        $rightCol->email = $invoice->getEmail();
        $rightCol->address = implode(', ', array_filter(array($invoice->getStreet(), $invoice->getCity())));
        $rightCol->country = implode(', ', array_filter(array($this->getState($invoice),
            $invoice->getZip(),
            $this->getCountry($invoice))));

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_COL_RIGHT, array(
            'col' => $rightCol,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));

        foreach ($rightCol as $line)
        {
            $page->drawText($line, $right, $pointerR, 'UTF-8', Am_Pdf_Page_Decorator::ALIGN_RIGHT);
            $page->nl($pointerR);
        }

        $pointer = min($pointerR, $pointerL);

        $p = new stdClass();
        $p->value = & $pointer;

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_BEFORE_TABLE, array(
            'page' => $page,
            'pointer' => $p,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));


        $table = new Am_Pdf_Table();
        $table->setMargin($padd, $padd, $padd, $padd);
        $table->setStyleForRow(
            1, array(
            'shape' => array(
                'type' => Zend_Pdf_Page::SHAPE_DRAW_STROKE,
                'color' => new Zend_Pdf_Color_Html("#cccccc")
            ),
            'font' => array(
                'face' => $fontHB,
                'size' => 10
            )
            )
        );

        $table->setStyleForColumn(//num
            1, array(
            'align' => 'right',
            'width' => $width_num
            )
        );

        $table->setStyleForColumn(//qty
            3, array(
            'align' => 'right',
            'width' => $width_qty
            )
        );
        $table->setStyleForColumn(//price
            4, array(
            'align' => 'right',
            'width' => $width_price
            )
        );
        $table->setStyleForColumn(//total
            5, array(
            'align' => 'right',
            'width' => $width_total
            )
        );

        $table->addRow(array(
            ___('#'),
            ___('Subscription/Product Title'),
            ___('Qty'),
            ___('Unit Price'),
            ___('Total Price')
        ));

        $num = 0;
        foreach ($invoice->getItems() as $p)
        {
            /* @var $p InvoiceItem */
            $table->addRow(array(
                ++$num . '.',
                $p->item_title,
                $p->qty,
                $invoice->getCurrency($this->isFirstPayment() ? $p->first_price : $p->second_price),
                $invoice->getCurrency($this->isFirstPayment() ? $p->getFirstSubtotal() : $p->getSecondSubtotal())
            ));
        }

        $pointer = $page->drawTable($table, 0, $pointer);


        $table = new Am_Pdf_Table();
        $table->setMargin($padd, $padd, $padd, $padd);

        $table->setStyleForColumn(
            2, array(
            'align' => 'right',
            'width' => $width_total
            )
        );

        $subtotal = $this->isFirstPayment() ? $invoice->first_subtotal : $invoice->second_subtotal;
        $total = $this->isFirstPayment() ? $invoice->first_total : $invoice->second_total;

        if ($subtotal != $total)
        {
            $table->addRow(array(
                ___('Subtotal'),
                $invoice->getCurrency($subtotal)
            ))->addStyle($styleBold);
        }

        if (($this->isFirstPayment() && $invoice->first_discount > 0) ||
            (!$this->isFirstPayment() && $invoice->second_discount > 0))
        {
            $table->addRow(array(
                ___('Coupon Discount'),
                $invoice->getCurrency($this->isFirstPayment() ? $invoice->first_discount : $invoice->second_discount)
            ));
        }


        $table->addRow(array(
            ___('Total'),
            $invoice->getCurrency($total)
        ))->addStyle($styleBold);

        $table->addRow(array(
            ___('Taxable Income'),
            "-" . $invoice->getCurrency(($this->getOrigPayment()->amount - $this->getOrigPayment()->tax))
        ))->addStyle($styleBold);

        if ($this->getOrigPayment()->tax>0)
            $table->addRow(array(
                ___('Tax') . " " . $invoice->tax_rate . "%",
                "-" . $invoice->getCurrency($this->getOrigPayment()->tax)
            ))->addStyle($styleBold);



        $table->addRow(array(
            ___('Amount Paid'),
            $invoice->getCurrency(sprintf("%.2f", $this->getOrigPayment()->amount - $this->payment->amount))
        ))->addStyle(array(
            'font' => array(
                'face' => $fontHB,
                'size' => 10
        )));

        $x = $this->getPaperWidth() - ($width_qty + $width_price + $width_total) - 2 * $padd;
        $pointer = $page->drawTable($table, $x, $pointer);
        $page->nl($pointer);
        $page->nl($pointer);

        if (!$this->getDi()->config->get('invoice_do_not_include_terms'))
        {
            $termsText = new Am_TermsText($invoice);
            $page->drawTextWithFixedWidth(___('Subscription Terms') . ': ' . $termsText, $left, $pointer, $this->getPaperWidth() - 2 * $padd);
            $page->nl($pointer);
        }

        $p = new stdClass();
        $p->value = & $pointer;

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_AFTER_TABLE, array(
            'page' => $page,
            'pointer' => $p,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));

        if (!$this->getDi()->config->get('invoice_custom_template') ||
            !$this->getDi()->uploadTable->load($this->getDi()->config->get('invoice_custom_template')))
        {
            if ($ifn = $this->getDi()->config->get('invoice_footer_note'))
            {
                $tmpl = new Am_SimpleTemplate();
                $tmpl->assignStdVars();
                $tmpl->assign('user', $user);
                $tmpl->assign('invoice', $invoice);
                $ifn = $tmpl->render($ifn);

                $page->nl($pointer);
                $page->drawTextWithFixedWidth($ifn, $left, $pointer, $this->getPaperWidth() - 2 * $padd);
            }
        }
        return $pdf->render();
    }

    function isFirstPayment()
    {
        return $this->getOrigPayment()->isFirst();
    }

    /**
     * 
     * @return InvoicePayment
     */
    function getOrigPayment()
    {
        if (empty($this->origPayment))
        {
            if($this->payment->invoice_payment_id)
            {
                $this->origPayment = $this->getDi()->invoicePaymentTable->load($this->payment->invoice_payment_id);
            }
            else
            {
                // Refund is not assigned to payment, get last payment which was not refunded..
                $payments  = $this->getDi()->invoicePaymentTable->selectObjects(""
                    . "SELECT p.*, r.invoice_refund_id "
                    . "FROM  ?_invoice_payment p LEFT JOIN  ?_invoice_refund r "
                    . "ON p.invoice_payment_id = r.invoice_payment_id "
                    . "WHERE p.invoice_id=? AND p.dattm <= ?  "
                    . "HAVING r.invoice_refund_id IS NULL "
                    . "ORDER BY p.dattm DESC", $this->payment->invoice_id, $this->payment->dattm);
                $this->origPayment = $payments[0];
            }
                
            
        }
        return $this->origPayment;
    }

}
