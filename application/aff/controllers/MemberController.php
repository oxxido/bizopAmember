<?php

/*
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Affiliate pages
 *    FileName $RCSfile$
 *    Release: 4.6.3 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class Aff_MemberController extends Am_Controller
{

    /** @var User */
    protected $user;

    public function preDispatch()
    {
        $this->getDi()->auth->requireLogin(ROOT_URL . '/aff/member');
        $this->user = $this->getDi()->user;
        if (!$this->user->is_affiliate) {
            //throw new Am_Exception_InputError("Sorry, this page is opened for affiliates only");
            $this->_redirect('member');
        }
    }

    function indexAction()
    {
        $this->_redirect('aff/aff');
    }

    function statsAction()
    {
        require_once 'Am/Report.php';
        require_once 'Am/Report/Standard.php';
        include_once APPLICATION_PATH . '/aff/library/Reports.php';

        if ($this->getDi()->config->get('aff.affiliate_can_view_details') && $detailDate = $this->getFiltered('detailDate')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $detailDate))
                throw new Am_Exception_InputError("Wrong date passed");
            $c = 0;
            foreach ($this->getDi()->affCommissionTable->fetchByDate($detailDate, $this->user->user_id) as $c) {
                $c++;
                $p = $c->getPayment();
                if (!$p)
                    continue;
                $u = $p->getUser();
                $i = $p->getInvoice();
                $product = $c->getProduct();
                $s = sprintf('%s (%s) &ndash; <strong>%s</strong>%s', $this->escape($u->name_f . '&nbsp;' . $u->name_l),
                    ___($product->title), Am_Currency::render($c->amount),
                    ($c->tier ? sprintf(' (%d-tier)', $c->tier+1) : ''));
                if ($c->record_type == AffCommission::VOID)
                    $s = "<div style='color: red'>$s (void)</div>";
                echo $s . "<br />\n";
            }
            if (!$c)
                echo ___('No commissions on this date');

            return;
        }


        $rs = new Am_Report_AffStats();
        $rs->setAffId($this->user->user_id);
        $rc = new Am_Report_AffClicks();
        $rc->setAffId($this->user->user_id);

        if (!$this->getInt('monthyear')) {
            $firstDate[] = $this->getDi()->db->selectCell("SELECT MIN(date) FROM ?_aff_commission WHERE aff_id=?d", $this->user->user_id);
            $firstDate[] = current(explode(' ', $this->getDi()->db->selectCell("SELECT MIN(`time`) FROM ?_aff_click WHERE aff_id=?d", $this->user->user_id)));
            $rs->setInterval(min($firstDate), 'now')->setQuantity(new Am_Report_Quant_Month());
        } else {
            $ym = $this->getInt('monthyear');
            if (!$ym || strlen($ym) != 6)
                $ym = date('Ym');
            $start = mktime(0, 0, 0, substr($ym, 4, 2), 1, substr($ym, 0, 4));
            $rs->setInterval(date('Y-m-d 00:00:00', $start), date('Y-m-t 23:59:59', $start))->setQuantity(new Am_Report_Quant_Day());
            $this->view->period = array(date('Y-m-d 00:00:00', $start), date('Y-m-t 23:59:59', $start));
        }
        $rc->setInterval($rs->getStart(), $rs->getStop())->setQuantity(clone $rs->getQuantity());

        $result = $rs->getReport();
        $rc->getReport($result);

        $output = new Am_Report_Graph_Line($result);
        $output->setSize('100%', 300);
        $this->view->report = $output->render();

        /* extract data from report to show it in view */
        $rows = array();
        $totals = array();
        $lines = $result->getLines();
        foreach ($result->getPointsWithValues() as $r) {
            /* @var $r Am_Report_Point */
            if ($result->getQuantity()->getId() == 'month') {
                $hasValue = false;
                foreach ($lines as $line) {
                    if ($r->getValue($line->getKey())) {
                        $hasValue = true;
                        break;
                    }
                }
                $href = $hasValue ? $this->view->url(array("monthyear"=>$r->getKey())) : '';
            } elseif ($this->getModule()->getConfig('affiliate_can_view_details')) {
                $href = "javascript: showAffDetails('{$r->getKey()}')";
            } else {
                $href = "";
            }
            $rows[$r->getKey()]['date'] = $r->getLabel();
            $rows[$r->getKey()]['date_href'] = $href;
            foreach ($lines as $i=>$line){
                list($start, $stop) = $result->getQuantity()->getStartStop($r->getKey());

                $href = $r->getValue($line->getKey()) > 0 ?
                    sprintf("javascript:affDetail('%s', '%s', '%s')", $start, $stop, $r->getLabel()) :
                    null;

                $rows[$r->getKey()][$line->getKey() . '_href'] = $href;
                $rows[$r->getKey()][$line->getKey()] = $r->getValue($line->getKey());

                $totals[$line->getKey()] = @$totals[$line->getKey()]+$r->getValue($line->getKey());
            }
        }
        $this->view->totals = $totals;
        $this->view->rows = $rows;
        $this->view->result = $result;
        $this->view->display('aff/stats.phtml');
    }

    public function payoutInfoAction()
    {
        $form = new Am_Form;
        $form->setAction($this->getUrl());
        $this->getModule()->addPayoutInputs($form);
        $form->addSubmit('_save', array('value' => ___('Save')));
        $form->addDataSource(new Am_Request($d = $this->user->toArray()));
        if ($form->isSubmitted() && $form->validate()) {
            foreach ($form->getValue() as $k => $v) {
                if ($k[0] == '_')
                    continue;
                if ($k == 'aff_payout_type')
                    $this->user->set($k, $v);
                else
                    $this->user->data()->set($k, $v);
            }
            $this->user->update();
        }

        $this->view->form = $form;
        $this->view->display('aff/payout-info.phtml');
    }

    public function payoutAction()
    {
        $query = new Am_Query($this->getDi()->affPayoutDetailTable);
        $query->leftJoin('?_aff_payout', 'p', 'p.payout_id=t.payout_id');
        $query->addField('p.*')
            ->addWhere('aff_id=?',  $this->user->pk());

        $this->view->payouts = $query->selectAllRecords();
        $this->view->display('aff/payout.phtml');
    }

    public function clicksDetailAction()
    {
        $date_from = $this->getFiltered('from');
        $date_to = $this->getFiltered('to');
        $this->view->clicks = $this->getDi()->affClickTable->fetchByDateInterval($date_from, $date_to, $this->getDi()->auth->getUserId());
        $this->view->display('/aff/clicks-detail.phtml');

    }
}