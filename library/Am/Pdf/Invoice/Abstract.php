<?php

if (!@class_exists('Zend_Pdf_Page', true))
    include_once('Zend/Pdf_Pack.php');

/**
 * @package Am_Pdf  
 */
abstract
    class Am_Pdf_Invoice_Abstract
{

    /** @var Invoice */
    protected
        $invoice;

    /** @var InvoicePayment */
    protected
        $payment;

    /** @var Am_Di */
    protected
        $di = null;

    /** @var int */
    protected
        $pointer;

    const
        PAPER_FORMAT_LETTER = Zend_Pdf_Page::SIZE_LETTER;
    const
        PAPPER_FORMAT_A4 = Zend_Pdf_Page::SIZE_A4;

    function setDi(Am_Di $di)
    {
        $this->di = $di;
    }

    /**
     *
     * @return Am_Di
     */
    function getDi()
    {
        return $this->di ? $this->di : Am_Di::getInstance();
    }

    public
        function getPaperWidth()
    {
        return $this->getDi()->config->get('invoice_format', self::PAPER_FORMAT_LETTER) == self::PAPER_FORMAT_LETTER ?
            Am_Pdf_Page_Decorator::PAGE_LETTER_WIDTH :
            Am_Pdf_Page_Decorator::PAGE_A4_WIDTH;
    }

    public
        function getPaperHeight()
    {
        return $this->getDi()->config->get('invoice_format', self::PAPER_FORMAT_LETTER) == self::PAPER_FORMAT_LETTER ?
            Am_Pdf_Page_Decorator::PAGE_LETTER_HEIGHT :
            Am_Pdf_Page_Decorator::PAGE_A4_HEIGHT;
    }

    public
        function drawDefaultTemplate(Zend_Pdf $pdf)
    {
        $pointer = $this->getPaperHeight() - 20;

        $page = new Am_Pdf_Page_Decorator($pdf->pages[0]);
        if (!($ic = $this->getDi()->config->get('invoice_contacts')))
        {
            $ic = $this->getDi()->config->get('site_title') . '<br>' . $this->getDi()->config->get('root_url');
        }

        $page->setFont($this->getFontRegular(), 10);

        $invoice_logo_id = $this->getDi()->config->get('invoice_logo');
        if ($invoice_logo_id && ($upload = $this->getDi()->uploadTable->load($invoice_logo_id, false)))
        {
            if (file_exists($upload->getFullPath()))
            {
                $image = null;

                switch ($upload->getType())
                {
                    case 'image/png' :
                        $image = new Zend_Pdf_Resource_Image_Png($upload->getFullPath());
                        break;
                    case 'image/jpeg' :
                        $image = new Zend_Pdf_Resource_Image_Jpeg($upload->getFullPath());
                        break;
                    case 'image/tiff' :
                        $image = new Zend_Pdf_Resource_Image_Tiff($upload->getFullPath());
                        break;
                }

                if ($image)
                {
                    $page->drawImage($image, 20, $pointer - 100, 220, $pointer);
                }
            }
        }

        $page->drawTextWithFixedWidth($ic, $this->getPaperWidth() - 20, $pointer, 400, 'UTF-8', Am_Pdf_Page_Decorator::ALIGN_RIGHT);
        $pointer-=110;
        $page->drawLine(20, $pointer, $this->getPaperWidth() - 20, $pointer);
        $page->nl($pointer);
        $page->nl($pointer);

        return $pointer;
    }

    /**
     *
     * @return Zend_Pdf
     *
     */
    public
        function createPdfTemplate()
    {
        if ($this->getDi()->config->get('invoice_custom_template') &&
            ($upload = $this->getDi()->uploadTable->load($this->getDi()->config->get('invoice_custom_template'))))
        {
            $pdf = Zend_Pdf::load($upload->getFullPath());

            $this->pointer = $this->getPaperHeight() - $this->getDi()->config->get('invoice_skip', 150);
        }
        else
        {
            $pdf = new Zend_Pdf();
            $pdf->pages[0] = $pdf->newPage($this->getDi()->config->get('invoice_format', Zend_Pdf_Page::SIZE_LETTER));

            $this->pointer = $this->drawDefaultTemplate($pdf);
        }

        return $pdf;
    }

    //can be called only after createPdfTemplate
    public
        function getPointer()
    {
        return $this->pointer;
    }

    public
        function getFontRegular()
    {
        if ($this->getDi()->config->get('invoice_custom_ttf') &&
            ($upload = $this->getDi()->uploadTable->load($this->getDi()->config->get('invoice_custom_ttf'))))
            return Zend_Pdf_Font::fontWithPath($upload->getFullPath());
        else
            return Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);
    }

    public
        function getFontBold()
    {
        if ($this->getDi()->config->get('invoice_custom_ttfbold') &&
            ($upload = $this->getDi()->uploadTable->load($this->getDi()->config->get('invoice_custom_ttfbold'))))
            return Zend_Pdf_Font::fontWithPath($upload->getFullPath());
        else
            return Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
    }

    public
        function getFileName()
    {
        $filename = $this->getDi()->config->get('invoice_filename', 'amember-invoice-%public_id%.pdf');

        $filename = str_replace('%payment.date%', date('Y-m-d', amstrtotime($this->payment->dattm)), $filename);

        $tmp = new Am_SimpleTemplate();
        $tmp->assign('public_id', $this->invoice->public_id);
        $tmp->assign('receipt_id', $this->payment->receipt_id);
        $tmp->assign('payment', $this->payment);
        $tmp->assign('invoice', $this->invoice);
        $tmp->assign('user', $this->invoice->getUser());

        return $tmp->render($filename);
    }

    abstract
        function render();

    public
        function getState(Invoice $invoice)
    {
        $state = $this->getDi()->stateTable->findFirstBy(array(
            'state' => $invoice->getState()
        ));
        return $state ? $state->title : $invoice->getState();
    }

    public
        function getCountry(Invoice $invoice)
    {
        $country = $this->getDi()->countryTable->findFirstBy(array(
            'country' => $invoice->getCountry()
        ));
        return $country ? $country->title : $invoice->getCountry();
    }

}
