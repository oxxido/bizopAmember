<?php

class Am_Pdf_Invoice_InvoicePayment extends Am_Pdf_Invoice_Abstract
{

    function __construct(InvoicePayment $payment)
    {
        $this->invoice = $payment->getInvoice();
        $this->payment = $payment;
    }

    public
        function isFirstPayment()
    {
        return $this->payment->isFirst();
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
        $leftCol->invoiceNumber = ___('Invoice Number: ') . $payment->getDisplayInvoiceId();
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

        if ($this->getDi()->config->get('invoice_include_access'))
        {
            $pointer = $this->renderAccess($page, $pointer);
        }

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

        if (($this->isFirstPayment() && $invoice->first_tax > 0) ||
            (!$this->isFirstPayment() && $invoice->second_tax > 0))
        {
            $table->addRow(array(
                ___('Tax Amount') . sprintf(' (%d%s)', $invoice->tax_rate, '%'),
                $invoice->getCurrency($this->isFirstPayment() ? $invoice->first_tax : $invoice->second_tax)
            ));
        }

        $table->addRow(array(
            ___('Total'),
            $invoice->getCurrency($total)
        ))->addStyle($styleBold);

        if (!$this->getDi()->config->get('different_invoice_for_refunds') || !(defined('AM_ADMIN') && AM_ADMIN))
        {
            $refunds = $this->getDi()->invoiceRefundTable->findBy(array('invoice_payment_id' => $payment->pk()));
            if ($refunds)
            {
                $refunds_total = 0;
                foreach ($refunds as $r)
                {
                    $refunds_total += $r->amount;
                    $table->addRow(array(
                        ___('Refund') . "<br/>" . amDate($r->dattm) . " " . amTime($r->dattm) . "",
                        "-" . $invoice->getCurrency($r->amount)
                    ))->addStyle(array(
                        'font' => array(
                            'face' => $fontHB,
                            'size' => 10
                        ),
                    ));
                }


                $table->addRow(array(
                    ___('Amount Paid'),
                    $invoice->getCurrency(sprintf("%.2f", $payment->amount - $refunds_total))
                ))->addStyle(array(
                    'font' => array(
                        'face' => $fontHB,
                        'size' => 10
                )));
            }
        }
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

    public
        function renderAccess($page, $pointer)
    {
        $invoice = $this->invoice;
        //if user is not approved there is no access records
        $accessrecords = $invoice->getAccessRecords();
        if (!$accessrecords)
            return $pointer;
        $payment = $this->payment;

        $padd = 20;
        $width_date = 120;

        $fontH = $this->getFontRegular();
        $fontHB = $this->getFontBold();


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

        $table->setStyleForColumn(//from
            2, array(
            'width' => $width_date
            )
        );
        $table->setStyleForColumn(//to
            3, array(
            'width' => $width_date
            )
        );

        $table->addRow(array(
            ___('Subscription/Product Title'),
            ___('Begin'),
            ___('Expire')
        ));

        $get_product_ids = create_function('$access', 'return $access->product_id;');
        $productOptions = $this->getDi()->productTable->getProductTitles(array_map($get_product_ids, $accessrecords));

        foreach ($accessrecords as $a)
        {

            /* @var $a Access */
            if ($a->invoice_payment_id != $payment->pk())
                continue;
            $table->addRow(array(
                $productOptions[$a->product_id],
                amDate($a->begin_date),
                $a->expire_date == Am_Period::MAX_SQL_DATE ? ___('Lifetime') : amDate($a->expire_date)
            ));
        }

        $pointer = $page->drawTable($table, 0, $pointer);


        return $pointer;
    }

}
