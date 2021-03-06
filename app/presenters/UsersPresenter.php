<?php

namespace App\Presenters;

use Nette,
    App\Model,
    \Authorizator,
    Nette\Application\UI\Form,
    Nette\Utils\Html;


/**
 * Presenter to manage users.
 */
class UsersPresenter extends BugsBasePresenter
{
    const DEFAULT_ROLE = Authorizator::ROLE_REGISTERED;
    /* ***** EDIT HERE - BEGIN */
    const MY_DOMAIN = 'moje-domena.cz'; // make sure MX record(s) are good-to-go
    /* ***** EDIT HERE - END */

    public function renderDefault()
    {
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'manage'))
        {
            $this->flashMessage("Ke správě uživatelů nemáte oprávnění!", 'warning');
            $this->redirectToLogin($this->getName());
        }

        $this->template->users = $this->db->table(Authorizator::USERS_TABLE)->where(array(self::DELETED_COLUMN => FALSE))->order('surname, name');
    }

    protected function createComponentAddUserForm()
    {
        $form = new Form;
        $form->addProtection();

        $form->addText('name', 'Jméno:')
            ->setRequired();
        $form->addText('surname', 'Příjmení:')
            ->setRequired();
        $form->addText('username', 'E-mail:')
            ->addRule(Form::EMAIL)
            ->setRequired();

        $form->addSubmit('save', 'Přidat')
            ->setAttribute('class', 'small success')
            ->onClick[] = callback($this, 'addUserFormSubmitted');
        $presenter = $this;
        $form->addSubmit('cancel', 'Zrušit')
            ->setAttribute('class', 'small secondary')
            ->setValidationScope(FALSE)
            ->onClick[] = callback($this, 'cancel');

        return $form;
    }

    public function addUserFormSubmitted($submitButton)
    {
        $values = $submitButton->getForm()->getValues();
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'manage'))
        {
            $this->flashMessage("K přidání uživatelů nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        $password = $this->generateRandomString();

        try
        {
            $this->db->table(Authorizator::USERS_TABLE)->insert(array_merge((array) $values, array(
                'role' => self::DEFAULT_ROLE,
                \App\Model\UserManager::COLUMN_PASSWORD_HASH => Nette\Security\Passwords::hash($password)
            )));
            $this->flashMessage('Uživatel ' . $values->name . ' ' . $values->surname . ' byl přidán!', 'success');

            $this->sendPasswordViaEmail($values->username, $password);
            $msg = Html::el('p', 'Přihlašovací údaje byly uživateli odeslány na ')->add(Html::el('a', $values->username)->href('mailto:' . $values->username));
            $this->flashMessage($msg);
        }
        catch (\PDOException $e)
        {
            if ($e->errorInfo[1] == 1062)
            {
                $this->flashMessage('Uživatele nelze přidat, protože tento email v databázi již existuje!', 'error');
            }
            else
            {
                $this->flashMessage($e->getMessage(), 'error');
            }
        }

        $this->redirectHere();
    }

    public function renderEdit($id)
    {
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'editTheirOwn', $this->user->id, $id))
        {
            $this->flashMessage("Ke správě ostatních uživatelů nemáte oprávnění!", 'warning');
            $this->redirectHere('edit', $this->user->id);
        }

        $this->template->myUser = $this->db->table(Authorizator::USERS_TABLE)->get($id);
    }

    protected function createComponentEditUserForm()
    {
        $editedUserId = $this->getParam('id');

        $form = new Form;
        $form->addProtection();

        $form->addHidden('id', $editedUserId);
        $form->addText('name', 'Jméno:')
            ->setRequired();
        $form->addText('surname', 'Příjmení:')
            ->setRequired();
        $form->addText('username', 'E-mail:')
            ->addRule(Form::EMAIL)
            ->setRequired();

        if ($this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'manage') && $editedUserId != $this->user->id)
        {
            $form->addSelect('role', 'Role:', array(
                Authorizator::ROLE_ADMIN => 'administrátor',
                Authorizator::ROLE_REGISTERED => 'registrovaný'
            ));
        }

        $defaults = $this->db->table(Authorizator::USERS_TABLE)->get($this->getParam('id'));
        $form->setDefaults($defaults);

        $form->addSubmit('send', 'Uložit')
            ->setAttribute('class', 'small success')
            ->onClick[] = callback($this, 'editUserFormSubmitted');
        $presenter = $this;
        $form->addSubmit('cancel', 'Zpět')
            ->setAttribute('class', 'small secondary')
            ->setValidationScope(FALSE)
            ->onClick[] = callback($this, 'cancel');

        return $form;
    }

    public function editUserFormSubmitted($submitButton)
    {
        $values = $submitButton->getForm()->getValues();
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'editTheirOwn', $this->user->id, $values->id))
        {
            $this->flashMessage("K úpravě tohoto uživatele nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        try
        {
            $this->db->table(Authorizator::USERS_TABLE)->get($values->id)->update($values);
            $this->flashMessage('Změny byly uloženy.', 'success');
        }
        catch(\PDOException $e)
        {
            if ($e->errorInfo[1] == 1062)
            {
                $this->flashMessage('Tento email v databázi již existuje.', 'error');
            }
            else
            {
                $this->flashMessage($e->getMessage(), 'error');
            }
        }
        $this->redirectHere('edit', $values->id);
    }

    protected function createComponentChangePasswordForm()
    {
        $form = new Form;
        $form->addProtection();

        $form->addHidden('id', $this->getParam('id'));
        $form->addPassword('oldPassword', 'Současné heslo:')
            ->setRequired();
        $form->addPassword('newPassword', 'Nové heslo:')
            ->setRequired();
        $form->addPassword('newPassword2', 'Nové heslo (potvrzení):')
            ->setRequired()
            ->addConditionOn($form["newPassword2"], Form::FILLED)
                ->addRule(Form::EQUAL, "Hesla se musí shodovat !", $form["newPassword"]);
        $form->addSubmit('send', 'Změnit')
            ->setAttribute('class', 'small success')
            ->onClick[] = callback($this, 'changePasswordFormSubmitted');
        $presenter = $this;
        $form->addSubmit('cancel', 'Zpět')
            ->setAttribute('class', 'small secondary')
            ->setValidationScope(FALSE)
            ->onClick[] = callback($this, 'cancel');

        return $form;
    }

    public function changePasswordFormSubmitted($submitButton)
    {
        $values = $submitButton->getForm()->getValues();
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'editTheirOwn', $this->user->id, $values->id))
        {
            $this->flashMessage("K úpravě hesla tohoto uživatele nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        $user = $this->db->table(Authorizator::USERS_TABLE)->get($values->id);

        if (\Nette\Security\Passwords::verify($values->oldPassword, $user[\App\Model\UserManager::COLUMN_PASSWORD_HASH]))
        {
            $this->getUser()->login($user->username, $values->oldPassword);
            $this->db->table(Authorizator::USERS_TABLE)->get($values->id)->update(array(\App\Model\UserManager::COLUMN_PASSWORD_HASH => Nette\Security\Passwords::hash($values->newPassword)));
            $this->flashMessage('Heslo bylo změněno.', 'success');
            $this->redirectHere('edit', $values->id);
        }
        else
        {
            $this->flashMessage('Současné heslo se neshoduje.', 'error');
        }
    }

    public function actionGenerateNewPassword($id)
    {
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'editTheirOwn', $this->user->id, $id))
        {
            $this->flashMessage("Ke změně hesla jiného uživatele nemáte oprávnění!", 'warning');
            $this->redirectHere();
        }

        $user = $this->db->table(Authorizator::USERS_TABLE)->get($id);
        $newPassword = $this->generateRandomString();
        $user->update(array(\App\Model\UserManager::COLUMN_PASSWORD_HASH => Nette\Security\Passwords::hash($newPassword)));
        $this->sendPasswordViaEmail($user->username, $newPassword);
        $this->flashMessage('Uživateli bylo vygenerováno nové heslo a zasláno na jeho emailovou adresu.');
        $this->redirectHere();
    }

    public function actionDelete($id)
    {
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::USERS_RESOURCE, 'deleteTheirOwn', $this->user->id, $id))
        {
            $this->flashMessage("K smazání tohoto uživatele nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        if ($this->user->id == $id)
        {
            $this->flashMessage('Nelze smazat vlastní účet.', 'error');
            $this->redirectHere();
        }

        try
        {
            $user = $this->db->table(Authorizator::USERS_TABLE)->get($id);
            $user->update(array(
                'username' => NULL,
                self::DELETED_COLUMN => TRUE
            ));
            $this->flashMessage('Uživatel ' . $user->name . ' ' . $user->surname . ' byl smazán!', 'success');
        }
        catch (\PDOException $e)
        {
            if ($e->errorInfo[1] == 1451)
            {
                $this->flashMessage('Uživatele nelze smazat, protože je autorem událostí, příspěvků nebo komentářů ve fóru nebo vlastníkem některých souborů!', 'error');
            }
            else
            {
                $this->flashMessage($e->getMessage(), 'error');
            }
        }
        $this->redirectHere();
    }

    public function renderBulkEmail() {
        if (!$this->acl->isAllowed($this->user->roles, Authorizator::EMAILS_RESOURCE, 'send'))
        {
            $this->flashMessage("K odeslání hromadného emailu nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        $this->template->users = $this->db->table(Authorizator::USERS_TABLE)->where(array(self::DELETED_COLUMN => FALSE))->order('surname, name');
    }

    protected function createComponentSendBulkEmailForm()
    {
        $form = new Form;
        $form->addProtection();
        $form->getElementPrototype()->id = 'sendBulkEmailForm';

        $users = $this->db->table(Authorizator::USERS_TABLE)->where(array(
            self::DELETED_COLUMN => FALSE
        ));

        foreach ($users as $user) {
            $form->addCheckbox($user->id, ' ' . $user->surname . ', ' . $user->name . ' (' . $user->username . ')')
                ->setDefaultValue(true);
        }

        $form->addTextarea('text', 'Text:', NULL, 8)
            ->setAttribute('class', 'editable')
            ->setAttribute('placeholder', "Vážení uživatelé, ...")
            ->setRequired();

        $form->addText('subject', 'Předmět:')
            ->setAttribute('placeholder', "Předmět emailu")
            ->setRequired();

        $form->addSubmit('send', 'Odeslat hromadný email')
            ->setAttribute('class', 'button small action')
            ->onClick[] = callback($this, 'sendBulkEmailFormSubmitted');

        return $form;
    }

    public function sendBulkEmailFormSubmitted($submitButton)
    {
        $values = $submitButton->getForm()->getValues();
        $text = $values->text;
        unset($values->text);
        $subject = $values->subject;
        unset($values->subject);

        if (!$this->acl->isAllowed($this->user->roles, Authorizator::EMAILS_RESOURCE, 'send'))
        {
            $this->flashMessage("K odeslání hromadného emailu nemáš oprávnění!", 'warning');
            $this->redirectHere();
        }

        $to = array();
        foreach ($values as $id => $checked) {
            if ($checked) {
                array_push($to, $this->db->table(Authorizator::USERS_TABLE)->get($id)->username);
            }
        }

        if (!count($to)) {
            $this->flashMessage("Je třeba uvést alespoň jednoho adresáta!", 'warning');
        } else {
            $this->sendBulkEmail($to, $subject, $text);
            $this->flashMessage("Mail byl úspěšně odeslán!", 'success');
            $this->redirectHere();
        }
    }

    private function generateRandomString($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++)
        {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function sendPasswordViaEmail($to, $password)
    {
        /* ***** EDIT HERE - BEGIN */
        $mail = new Nette\Mail\Message;
        $mail->setFrom('no-reply@' . self::MY_DOMAIN)
            ->addTo($to)
            ->addBcc('jiri.zajic@flipcom.cz')
            ->setSubject('Registrace na ' . self::MY_DOMAIN)
            ->setBody('Přihlašovacími údaji k webu http://www.' . self::MY_DOMAIN . ' jsou Váš email a heslo: ' . $password);
        /* ***** EDIT HERE - END */
        $mailer = new Nette\Mail\SendmailMailer;
        $mailer->send($mail);
    }

    private function sendBulkEmail($to, $subject, $text) {
        $mail = new Nette\Mail\Message;
        /* ***** EDIT HERE - BEGIN */
        $mail->setFrom('administrator@' . self::MY_DOMAIN);
        /* ***** EDIT HERE - END */
        $mail->setSubject($subject)
            ->setHTMLBody($text);
        foreach ($to as $recipient) {
            $mail->addTo($recipient);
        }
        $mailer = new Nette\Mail\SendmailMailer;
        $mailer->send($mail);
    }

}
