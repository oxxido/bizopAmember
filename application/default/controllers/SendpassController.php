<?php
/*
*   Send lost password
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Send lost password page
*    FileName $RCSfile$
*    Release: 4.6.3 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class SendpassController extends Am_Controller {
    const EXPIRATION_PERIOD = 8; //hrs
    const STORE_PREFIX = 'sendpass-';
    const SECURITY_VAR = '_s';

    public function indexAction()
    {
        if ($code = $this->getFiltered(self::SECURITY_VAR)) {
            if ($this->checkCode($code, $user)) {
                $this->doChangePass($user, $code);
            } else {
                throw new Am_Exception_InputError(___('Security code is either invailed or expired'));
            }
        } else {
           $this->doSend();
        }
    }

    public function doSend()
    {
        do {
            $login = trim($this->getParam('login'));
            $user = $this->getDi()->userTable->findFirstByLogin($login);

            if (!$user)
                $user = $this->getDi()->userTable->findFirstByEmail($login);

            if (!$user) {
                $title = ___('Lost Password Sending Error');
                $text = ___('The information you have entered is incorrect. ' .
                    'Username [%s] does not exist in database', $this->getEscaped('login'));
                $ok = false;
                break;
            }

            if ($error = $this->checkLimits($user)) {
                $title = ___('Lost Password Sending Error');
                $text = $error;
                $ok = false;
                break;
            }

            $this->sendSecurityCode($user);
            $title = ___('Lost Password Sent');
            $text = ___('Your password has been e-mailed to you. ' .
                'Please check your mailbox');
            $ok = true;

        } while (false);

        if ($this->isAjax())
        {
            return $this->ajaxResponse(array('ok'=>$ok, 'error'=>array($text)));
        }

        $this->view->title = $title;
        $this->view->content = $text;
        $this->view->display('layout.phtml');
    }

    public function doChangePass(User $user, $code)
    {
        $form = $this->createForm();
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                        self::SECURITY_VAR => $code,
                        'login' => $user->login
                    )));

        if ($form->isSubmitted() && $form->validate())
        {
            $user->setPass($this->getParam('pass0'));
            $user->save();
            // Password has been reset. Delete all other sessions besides of current one. (Logout other' users)
            $this->getDi()->db->query('DELETE FROM ?_session where user_id=? and id<>?', $user->pk(), session_id());
            $this->getDi()->store->delete(self::STORE_PREFIX . $code);

            $msg = ___('Your password has been changed successfully. ' .
                    'You can %slogin to your account%s with new password.',
                        sprintf('<a href="%s">', $this->escape(REL_ROOT_URL . '/login')), '</a>');
            $this->view->title = ___('Change Password');
            $this->view->content = <<<CUT
   <div class="am-info">$msg</div>
CUT;
            $this->view->display('layout.phtml');
        } else {
            $this->view->form = $form;
            $this->view->display('changepass.phtml');
        }

    }

    protected function createForm()
    {
        $form = new Am_Form();

        $form->addCsrf();
        $form->addText('login', array('disabled'=>'disabled'))
                ->setLabel(___('Username'));

        $pass0 = $form->addPassword('pass0')
            ->setLabel(___('New Password'));
        $pass0->addRule('minlength',
                ___('The password should be at least %d characters long', $this->getDi()->config->get('pass_min_length', 4)),
                $this->getDi()->config->get('pass_min_length', 4));
        $pass0->addRule('maxlength',
                ___('Your password is too long'),
                $this->getDi()->config->get('pass_max_length', 32));
        $pass0->addRule('required', 'This field is required');
        if ($this->getDi()->config->get('require_strong_password')) {
            $pass0->addRule('regex', ___('Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars'),
                $this->getDi()->userTable->getStrongPasswordRegex());
        }

        $pass1 = $form->addPassword('pass1')
            ->setLabel(___('Confirm Password'));

        $pass1->addRule('eq', ___('Passwords do not match'), $pass0);

        $form->addHidden(self::SECURITY_VAR);
        $form->addSaveButton(___('Change Password'));
        return $form;
    }

    protected function checkLimits(User $user)
    {
        // Check limits by email.
        $attempt = $this->getDi()->store->get('remind-password_' . $user->email);
        if ($attempt>=2) {
            return ___('The message containing your password has already been sent to your inbox. Please wait 180 minutes for retrying');
        }
        $this->getDi()->store->set('remind-password_' . $user->email, ++$attempt, '+3 hours');

        // Check limits by IP address.
        $attempt_ip = $this->getDi()->store->get('remind-password_ip_' . $this->getRequest()->getClientIp(true));
        if ($attempt_ip>=5) {
            return ___('Too many Lost Password requests. Please wait 180 minutes for retrying');
        }
        $this->getDi()->store->set('remind-password_ip_' . $this->getRequest()->getClientIp(true), ++$attempt_ip, '+3 hours');
    }

    protected function checkCode($code, &$user)
    {
        $user_id = $this->getDi()->store->get(self::STORE_PREFIX . $code);
        if (!$user_id)
            return false;

        $user = $this->getDi()->userTable->load($user_id);
        return true;
    }

    protected function sendSecurityCode(User $user)
    {
        $security_code = $this->getDi()->app->generateRandomString(16);

        $et = Am_Mail_Template::load('send_security_code', $user->lang, true);
        $et->setUser($user);
        $et->setUrl(sprintf('%s/sendpass/?%s=%s',
                ROOT_SURL,
                self::SECURITY_VAR,
                $security_code)
        );
        $et->setHours(self::EXPIRATION_PERIOD);
        $et->send($user);

        $this->getDi()->store->set(self::STORE_PREFIX . $security_code,
            $user->pk(), '+'.self::EXPIRATION_PERIOD.' hours');
    }
}
