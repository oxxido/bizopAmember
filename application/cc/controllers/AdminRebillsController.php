<?php

class Cc_AdminRebillsController extends Am_Controller_Grid
{

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('cc');
    }

    public function emptyZero($v)
    {
        return $v ? $v : '';
    }

    protected function createAdapter()
    {
        $q = new Am_Query(new CcRebillTable);
        $q->clearFields();
        $q->groupBy('rebill_date');
        $q->addField('rebill_date');
        $q->addField('(1)', 'is_log');
        $q->addField('COUNT(t.rebill_date)', 'total');
        $q->addField('SUM(IF(t.status=0, 1, 0))', 'status_0');
        $q->addField('SUM(IF(t.status=1, 1, 0))', 'status_1');
        $q->addField('SUM(IF(t.status=2, 1, 0))', 'status_2');
        $q->addField('SUM(IF(t.status=3, 1, 0))', 'status_3');
        $q->addField('SUM(IF(t.status=4, 1, 0))', 'status_4');
        $u = new Am_Query(new InvoiceTable, 'i');
        $u->addWhere('i.paysys_id IN (?a)', $this->getPlugins());
        $u->groupBy('rebill_date');
        $u->clearFields()->addField('i.rebill_date');
        $u->addField('(0)', 'is_log');
        $u->addField('COUNT(i.invoice_id)', 'total');
        for ($i = 1; $i < 6; $i++)
            $u->addField('(NULL)');
        $u->leftJoin('?_cc_rebill', 't', 't.rebill_date=i.rebill_date');
        $u->addWhere('i.rebill_date IS NOT NULL');
        $u->addWhere('t.rebill_date IS NULL');
        $q->addUnion($u);
        $q->addOrder('rebill_date', true);
        return $q;
    }

    public function createGrid()
    {
        $grid = new Am_Grid_ReadOnly('_r', 'Rebills by Date', $this->createAdapter(), $this->_request, $this->view);
        $grid->setPermissionId('cc');
        $grid->addField('rebill_date', 'Date', true)->setRenderFunction(array($this, 'renderDate'));
        $grid->addField('status_0', 'Processing Not Finished', true)->setFormatFunction(array($this, 'emptyZero'));
        $grid->addField('status_1', 'No CC Saved', true)->setFormatFunction(array($this, 'emptyZero'));
        $grid->addField('status_2', 'Error', true)->setFormatFunction(array($this, 'emptyZero'));
        $grid->addField('status_3', 'Success', true)->setFormatFunction(array($this, 'emptyZero'));
        $grid->addField('status_4', 'Exception!', true)->setFormatFunction(array($this, 'emptyZero'));
        $grid->addField('total', 'Total Records', true)->setRenderFunction(array($this, 'renderTotal'));
        $grid->addField('_action', '', true)->setRenderFunction(array($this, 'renderLink'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));
        return $grid;
    }

    public function getPlugins()
    {
        $this->getDi()->plugins_payment->loadEnabled();
        $ret = array();
        foreach ($this->getDi()->plugins_payment->getAllEnabled() as $ps)
            if ($ps instanceof Am_Paysystem_CreditCard || $ps instanceof Am_Paysystem_Echeck)
                $ret[] = $ps->getId();
        return $ret;
    }

    public function renderDate(CcRebill $obj)
    {
        $raw = $obj->rebill_date;
        $d = amDate($raw);
        return $this->renderTd("$d<input type='hidden' name='raw-date' value='$raw' /><input type='hidden' name='raw-r_p' value='" . $this->_request->get('_r_p') . "' />", false);
    }

    public function getTrAttribs(& $ret, $record)
    {
        if ($record->rebill_date > sqlDate('now'))
        {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    public function renderTotal(CcRebill $obj)
    {
        if ($obj->is_log) {
            return $this->renderTd($obj->total);
        } else {
            $url = REL_ROOT_URL . '/default/admin-payments/p/invoices/index/?' . http_build_query(array(
                            '_invoice_filter' => array(
                                'datf' => 'rebill_date',
                                'dat1' => amDate($obj->rebill_date),
                                'dat2' => amDate($obj->rebill_date)
                            ),
                            '_invoice_sort' => 'rebill_date'
                            ));
            return $this->renderTd(sprintf('<a href="%s" target="_top">%d</a>',
                $this->escape($url), $obj->total), false);
        }
    }

    public function renderLink(CcRebill $obj)
    {
        $linkRun = $linkDetail = '';

        if ($obj->rebill_date <= sqlDate('now')) {
            if ($obj->status_3 < $obj->total) {
                $iconRun = $this->getDi()->view->icon('retry', ___('Run'));
                $back_url = $this->grid->makeUrl();
                $linkRun = "<a href='javascript:;' class='run' id='run-{$obj->rebill_date}' data-back_url='$back_url'>$iconRun</a>";
            }
            if ($obj->is_log) {
                $iconDetail = $this->getDi()->view->icon('view', ___('Details'));
                $linkDetail = "<a href='javascript:;' class='detail' id='detail-{$obj->rebill_date}'>$iconDetail</a>";
            }
        }

        return "<td width='1%' nowrap>$linkRun $linkDetail</td>";
    }

    public function renderInvoiceLink($record)
    {
        return '<td><a href="' . REL_ROOT_URL . "/admin-user-payments/index/user_id/" .
        $record->user_id . "#invoice-" . $record->invoice_id . '" target=_top >' . $record->invoice_id . '/' . $record->public_id . '</a></td>';
    }

    public function init()
    {
        parent::init();

        $this->view->headScript()->appendScript($this->getJs());
        $this->view->placeholder('after-content')->append(
            "<div id='run-form' style='display:none'></div>");
    }

    public function getJs()
    {
        $title = ___('Run Rebill Manually');
        $title_details = ___('Details');
        return <<<CUT
    $(document).ready(function(){
        $(document).on('click', '#grid-r a.run', function(event){
            var date = $(this).attr("id").replace(/^run-/, '');
            var back_url = $(this).data('back_url');
            $("#run-form").load(window.rootUrl + "/cc/admin-rebills/run", { 'date' : date, 'back_url' : back_url}, function(){
                $("#run-form").dialog({
                    autoOpen: true
                    ,width: 500
                    ,buttons: {}
                    ,closeOnEscape: true
                    ,title: "$title"
                    ,modal: true
                });
            });
        });
        $(document).on('click', '#grid-r a.detail', function(event){
            var date = $(this).attr("id").replace(/^detail-/, '');
            var div = $('<div class="grid-wrap" id="grid-r_d"></div>');
            div.load(window.rootUrl + "/cc/admin-rebills/detail?_r_d_date=" + date , function(){
                div.dialog({
                    autoOpen: true
                    ,width: 800
                    ,buttons: {}
                    ,closeOnEscape: true
                    ,title: "$title_details"
                    ,modal: true
                    ,open: function(){
                        div.ngrid();
                    }
                });
            });
        });
    });
    $(function(){
        $(document).on('submit',"#run-form form", function(){
            $(this).ajaxSubmit({target: '#run-form'});
            return false;
        });
    });
CUT;
    }

    public function renderRun()
    {
        return (string) $form;
    }

    public function createRunForm()
    {
        $form = new Am_Form;
        $form->setAction($this->getUrl(null, 'run'));

        $s = $form->addSelect('paysys_id')->setLabel(___('Choose a plugin'));
        $s->addRule('required');
        foreach ($this->getModule()->getPlugins() as $p)
            $s->addOption($p->getTitle(), $p->getId());
        $form->addDate('date')->setLabel(___('Run Rebill Manually'))->addRule('required');
        $form->addHidden('back_url');
        $form->addSubmit('run', array('value' => ___('Run')));
        return $form;
    }

    public function detailAction()
    {
        $date = $this->getFiltered('_r_d_date');
        if (!$date)
            throw new Am_Exception_InputError('Wrong date');
        $grid = $this->createDetailGrid($date);
        $grid->isAjax(false);
        $grid->runWithLayout('admin/layout.phtml');
    }

    protected function createDetailGrid($date)
    {
        //    public $textNoRecordsFound = "No rebills today - most possible cron job was not running.";
        $q = new Am_Query($this->getDi()->ccRebillTable);
        $q->addWhere('t.rebill_date=?', $date);
        $q->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id');
        $q->addField('i.public_id', 'public_id');
        $q->addField('i.user_id', 'user_id');
        $grid = new Am_Grid_ReadOnly('_r_d', ___('Detailed Rebill Report for %s', amDate($date)), $q, $this->_request, $this->view);
        $grid->setPermissionId('cc');
        $grid->addField(new Am_Grid_Field_Date('tm_added', 'Started', true));
        $grid->addField(new Am_Grid_Field('invoice_id', 'Invoice#', true, '', array($this, 'renderInvoiceLink')));
        $grid->addField(new Am_Grid_Field_Date('rebill_date', 'Date', true))->setFormatDate();
        $grid->addField('status', 'Status', true)->setFormatFunction(array('CcRebill', 'getStatusText'));
        $grid->addField('status_msg', 'Message');
        $grid->setCountPerPage(10);
        return $grid;
    }

    public function runAction()
    {
        $date = $this->getFiltered('date');
        if (!$date)
            throw new Am_Exception_InputError("Wrong date");

        $form = $this->createRunForm();
        if ($form->isSubmitted() && $form->validate()) {
            $value = $form->getValue();
            $this->doRun($value['paysys_id'], $value['date']);
            echo sprintf('<div class="info">%s</div><script type="text/javascript">window.location.href="' . $value['back_url'] . '"</script>', ___('Rebill Operation Completed for %s', amDate($value['date'])));
        } else {
            echo $form;
        }
    }

    public function doRun($paysys_id, $date)
    {
        $this->getDi()->plugins_payment->load($paysys_id);
        $p = $this->getDi()->plugins_payment->get($paysys_id);

        // Delete all previous failed attempts for this date in order to rebill these invoices again.

        $this->getDi()->db->query("
            DELETE FROM ?_cc_rebill 
            WHERE rebill_date = ? AND  paysys_id = ? AND status <> ?
            ", $date, $paysys_id, ccRebill::SUCCESS);

        $p->ccRebill($date);
    }

}