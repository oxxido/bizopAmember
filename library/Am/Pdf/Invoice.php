<?php

/**
 * For backward compatibility class has the same interface as before. 
 */
class Am_Pdf_Invoice extends Am_Pdf_Invoice_InvoicePayment
{

    static
        function create($element)
    {
        $cname = "Am_Pdf_Invoice_" . get_class($element);

        if (!class_exists($cname))
            throw new Am_Exception_InternalError(sprintf(___("Unable to handle invoice, class: %s  undefined"), $cname));

        $pdfInvoice = new $cname($element);
        return $pdfInvoice;
    }

}
