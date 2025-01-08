<?php

/**
 * Content Controller
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2011.
 * Copyright (C) The National Library of Finland 2014-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.    See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace SISIS\Controller;

use Laminas\View\Model\ViewModel;
use Laminas\Http\Response;

/**
 * Controller for handling post requests for forms from content pages.
 *
 * @category SISIS
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Johannes Schlüßlhuber <johannes.schluesslhuber@ub.uni-muenchen.de>
 * @license
 * @link
 */
class ContentController extends \VuFind\Controller\ContentController
{

    public function contentAction()
    {
      $request = $this->getRequest();
      $basename = basename($request->getUriString());

      // Post request
      if($request->isPost()){
        if(str_starts_with($basename, "initiallogin")){
          return $this->initialloginAction();
        }else if(str_starts_with($basename, "resetpassword")){
          return $this->resetpasswordAction();
        }else{
          return $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
        }
      }

      return parent::contentAction();
    }

    /**
     * Handle post input from initiallogin form
     *
     * @return ViewModel
     */
    private function initialloginAction()
    {
      $request = $this->getRequest();
      $postData = $request->getPost()->toArray();
      $viewModel = parent::createViewModel(['page' => 'initiallogin']);

      if($request->isPost()){
          $cat_username = $postData['username'] ?? '';
          $startPassword = $postData['start_password'] ?? '';
          $newPassword = $postData['password'] ?? '';
          if($postData['password'] != $postData['new_password_rep']){
            $this->flashMessenger()->addErrorMessage($this->translate("Passwords do not match"));
            return $viewModel;
          }
          if($newPassword == $startPassword){
            $this->flashMessenger()->addErrorMessage($this->translate("password_error_auth_old"));
            return $viewModel;
          }
          if(strlen($postData['password']) < 4){
            $this->flashMessenger()->addErrorMessage($this->translate('password_minimum_length', ['%%minlength%%' => "4"]));
            return $viewModel;
          }
          if(strlen($postData['password']) > 12){
            $this->flashMessenger()->addErrorMessage($this->translate('password_maximum_length', ['%%maxlength%%' => "12"]));
            return $viewModel;
          }
          $result = $this->getILS()->initialLogin($cat_username, $startPassword, $newPassword);
          if(!$result['success']){
            $this->flashMessenger()->addErrorMessage($result['status']);
            return $viewModel;
          }
          $this->flashMessenger()->addSuccessMessage($result['status']);
      }

      $this->getAuthManager()->login($this->getRequest());
      return $this->forwardTo('MyResearch', 'CheckedOut');
    }

    /**
     * Handle post input from resetpassword form
     *
     * @return ViewModel
     */
    private function resetpasswordAction()
    {
      // TODO mail: absender, betreff, text in sprachdateien auslagern
      $request = $this->getRequest();
      $viewModel = parent::createViewModel(['page' => "resetpassword"]);
      $postData;
      $cat_username;

      if($request->isPost()){
        $postData = $request->getPost()->toArray();
        $cat_username = $postData['cat_username'] ?? '';

        if (empty($cat_username)){
          $viewModel->setVariable('success', false);
          $this->flashMessenger()->addErrorMessage($this->translate("Username cannot be blank"));
          return $ViewModel;
        }

        $result = $this->getILS()->resetPassword($cat_username);
        if(!$result['success']){
            $viewModel->setVariable('success', false);
            $this->flashMessenger()->addErrorMessage($result['status']);
            return $viewModel;
        }else{
          // send mail with new pw to user
          $randomString = $result['newPW'];
          $mailer = $this->serviceLocator->get(\VuFind\Mailer\Mailer::class);
          // Attempt to send the email and show an appropriate flash message:
          try {
            $mailer->send(
              $result['mail'],
              "mail:placeholder",
              "Neues Passwort generiert",
              "Für Ihr Bibliothekskonto wurde ein neues Passwort generiert:\r\n\r\nPasswort: {$randomString}\r\n\r\nBitte loggen Sie sich damit ein und legen Sie in Ihrem Bibliothekskonto ein neues Passwort fest.\r\n\r\nIhre Universitätsbibliothek der LMU München",
              null,
              null
            );
            $viewModel->setVariable('success', true);
            $this->flashMessenger()->addSuccessMessage($result['status']);
          } catch (MailException $e) {
            $viewModel->setVariable('success', false);
            $this->flashMessenger()->addErrorMessage($e->getDisplayMessage(), 'error');
          }
        }
      }

      return $viewModel;
    }

}

