<?php

namespace Drupal\bakery\Controller;

/**
 * @file
 * Router call back functions for bakery SSO functions.
 */

use Drupal\user\Entity\User;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormBuilder;
use Drupal\bakery\BakeryService;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Messenger\MessengerInterface;
#use Drupal\user\Form;

/**
 * Route callback functionlities.
 */
class BakeryController extends ControllerBase {

  protected $bakeryService;

  /**
   * For initilizing bakery service.
   *
   * @param object \Drupal\bakery\BakeryService $bakeryService
   *   For bakery service.
   */
  public function __construct(BakeryService $bakeryService) {
    $this->bakery_service = $bakeryService;
  }

  /**
   * When this controller is created, it will get the bakery.bakery_service.
   *
   * @param object \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   For getting Bakery service.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('bakery.bakery_service')
    );
  }

  /**
   * Special Bakery register callback registers the user and returns to minion.
   */
  public function bakeryRegister() {

    $cookie = $this->bakeryTasteOatmealCookie();
    if ($cookie) {
      // Valid cookie.
      // Destroy the current oatmeal cookie,
      // we'll set a new one when we return to the minion.
      $this->bakery_service->eatCookie('OATMEAL');
      // TODO: need to fix
      // if (variable_get('user_register', 1)) {.
      if (TRUE) {
        // Users are allowed to register.
        $data = array();
        // Save errors.
        $errors = array();
        $name = trim($cookie['data']['name']);
        $mail = trim($cookie['data']['mail']);

        // Check if user exists with same email.
        $account = user_load_by_mail($mail);
        if (!empty($account)) {
          $errors['mail'] = 1;
        }
        else {
          // Check username.
          $account = user_load_by_name($name);
          if (!empty($account)) {
            $errors['name'] = 1;
          }
        }
      }
      else {
        \Drupal::logger('bakery')->error('Main Bakery site user registration is disabled but users are trying to register from a subsite.');
        $errors['register'] = 1;
      }
      if (empty($errors)) {
        // Create user.
        if (!$cookie['data']['pass']) {
          $pass = user_password();
        }
        else {
          $pass = $cookie['data']['pass'];
        }
        $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
        $account = User::create();
        // Mandatory settings.
        $account->setPassword($pass);
        $account->enforceIsNew();
        $account->setEmail($mail);
        // This username must be unique and accept only a-Z,0-9, - _ @ .
        $account->setUsername($name);
        // Optional settings.
        $account->set("init", $mail);
        $account->set("langcode", $language);
        $account->set("preferred_langcode", $language);
        $account->set("preferred_admin_langcode", $language);
        // $user->set("setting_name", 'setting_value');.
        $account->activate();
        // Save user.
        $account->save();
        // Set some info to return to the minion.
        $data['uid'] = $account->id();
        $data['mail'] = $mail;
        \Drupal::logger('bakery')->notice('New external user: %name using module bakery from minion !minion.', array('%name' => $account->getUsername(), '!minion' => $cookie['minion']));
        // Redirect to minion.
        if (!$this->config('user.settings')->get('verify_mail')) {
          // Create identification cookie and log user in.
          $init = $this->bakery_service->initField($account->id());
          $this->bakery_service->bakeChocolatechipCookie($account->getUsername(), $account->getEmail(), $init);
          $this->bakery_service->userExternalLogin($account);
        }
        else {
          // The user needs to validate their email, redirect back to minion to
          // inform them.
          $errors['validate'] = 1;
        }

      }
      else {
        // There were errors.
        session_destroy();
      }

      // Redirect back to custom Bakery callback on minion.
      $data['errors'] = $errors;
      $data['name'] = $name;
      // Carry destination through return.
      if (isset($cookie['data']['destination'])) {
        $data['destination'] = $cookie['data']['destination'];
      }

      // Bake a new cookie for validation on the minion.
      $this->bakery_service->bakeOatmealCookie($name, $data);
      return new TrustedRedirectResponse($cookie['minion'] . 'bakery');
    }
    // Invalid request.
    throw new AccessDeniedHttpException();
  }

  /**
   * Special Bakery login callback authenticates the user and returns to minion.
   */
  public function bakeryLogin() {
    $cookie = $this->bakeryTasteOatmealCookie();
    if ($cookie) {
      $errors = [];
      // Remove the data pass cookie.
      $this->bakery_service->eatCookie('OATMEAL');

      // First see if the user_login form validation has any errors for them.
      $name = trim($cookie['data']['name']);
      $pass = trim($cookie['data']['pass']);

      $uid = \Drupal::service('user.auth')->authenticate($name, $pass);
      $account = \Drupal\user\Entity\User::load($uid);
      if ($account->id()) {
        $init = $this->bakery_service->initField($account->id());
        $this->bakery_service->bakeChocolatechipCookie($account->getUsername(), $account->getEmail(), $init);
        $user = \Drupal::currentUser();
        $user = $account;
        $edit = array('name' => $user->getUsername());
        user_login_finalize($account);
      }
      else {
        $errors['incorrect-credentials'] = 1;
      }

      if (!empty($errors)) {
        // Report failed login.
        \Drupal::logger('user')->notice('Login attempt failed for %user.', array('%user' => $name));
        // Clear the messages on the main's session,
        // since they were set during
        // drupal_form_submit() and will be displayed out of context.
        drupal_get_messages();
      }
      // Bake a new cookie for validation on the minion.
      $data = array(
        'errors' => $errors,
        'name' => $name,
      );
      // Carry destination through login.
      if (isset($cookie['data']['destination'])) {
        $data['destination'] = $cookie['data']['destination'];
      }
      $this->bakery_service->bakeOatmealCookie($name, $data);
      return new TrustedRedirectResponse($cookie['minion'] . 'bakery/login');
    }
    throw new AccessDeniedHttpException();
  }

  /**
   * Update the user's login time to reflect them validating their email.
   */
  public function bakeryEatThinmintCookie() {
    // Session was set in validate.
    $name = $_SESSION['bakery']['name'];
    unset($_SESSION['bakery']['name']);
    $minion = $_SESSION['bakery']['minion'];
    unset($_SESSION['bakery']['minion']);
    $uid = $_SESSION['bakery']['uid'];
    unset($_SESSION['bakery']['uid']);

    $account = user_load_by_name($name);
    if ($account) {
      // @todo
      db_query("UPDATE {users_field_data} SET login = :login WHERE uid = :uid", array(':login' => $_SERVER['REQUEST_TIME'], ':uid' => $account->id()));

      // Save UID provided by minion site.
      $this->bakerySaveMinionUid($account, $minion, $uid);
    }
  }

  /**
   * Respond with account information.
   */
  public function bakeryEatGingerbreadCookie() {
    // Session was set in validate.
    $name = $_SESSION['bakery']['name'];
    unset($_SESSION['bakery']['name']);
    $or_email = $_SESSION['bakery']['or_email'];
    unset($_SESSION['bakery']['or_email']);
    $minion = $_SESSION['bakery']['minion'];
    unset($_SESSION['bakery']['minion']);
    $minion_uid = $_SESSION['bakery']['uid'];
    unset($_SESSION['bakery']['uid']);

    $key = $this->config('bakery.settings')->get('bakery_key');

    $account = user_load_by_name($name);
    if (!$account && $or_email) {
      $account = user_load_by_mail($name);
    }
    if ($account) {
      $this->bakerySaveMinionUid($account, $minion, $minion_uid);

      $payload = array();
      $payload['name'] = $account->getUsername();
      $payload['mail'] = $account->getEmail();
      // For use in minion init field.
      $payload['uid'] = $account->id();
      // Add any synced fields.
      foreach ($this->config('bakery.settings')->get('bakery_supported_fields') as $type => $enabled) {
        if ($enabled && $account->$type) {
          $payload[$type] = $account->$type;
        }
      }
      $payload['timestamp'] = $_SERVER['REQUEST_TIME'];
      // Respond with encrypted and signed account information.
      $message = $this->bakery_service->bakeData($payload);
    }
    else {
      $message = t('No account found');
      header('HTTP/1.1 409 Conflict');
    }
    $this->moduleHandler()->invokeAll('exit');
    print $message;
    exit();
  }

  /**
   * Custom return for minion registration process.
   *
   * Redirects to the homepage on success or to
   * the register page if there was a problem.
   */
  public function bakeryRegisterReturn() {
    $cookie = $this->bakeryTasteOatmealCookie();

    if ($cookie) {
      // Valid cookie, now destroy it.
      $this->bakery_service->eatCookie('OATMEAL');

      // Destination in cookie was set before user left this site, extract it to
      // be sure destination workflow is followed.
      if (empty($cookie['data']['destination'])) {
        $destination = '<front>';
      }
      else {
        $destination = $cookie['data']['destination'];
      }

      $errors = $cookie['data']['errors'];
      if (empty($errors)) {
        drupal_set_message(t('Registration successful. You are now logged in.'));
        // Redirect to destination.
        $this->redirect($destination);
      }
      else {
        if (!empty($errors['register'])) {
          drupal_set_message(t('Registration is not enabled on @main. Please contact a site administrator.', array('@main' => $this->config('bakery.settings')->get('bakery_main'))), 'error');
          \Drupal::logger('bakery')->error('Main Bakery site user registration is disabled', array());
        }
        if (!empty($errors['validate'])) {
          // If the user must validate their email then we need to create an
          // account for them on the minion site.
          // Save a stub account so we have a minion UID to send.
          $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
          $account = User::create();
          // Mandatory settings.
          $account->setPassword(user_password());
          $account->enforceIsNew();
          $account->setEmail($cookie['data']['mail']);
          // This username must be unique and accept only a-Z,0-9, - _ @ .
          $account->setUsername($cookie['name']);
          // Optional settings.
          $account->set("init", $this->bakery_service->initField($cookie['data']['uid']));
          $account->set("langcode", $language);
          $account->set("preferred_langcode", $language);
          $account->set("preferred_admin_langcode", $language);
          // $user->set("setting_name", 'setting_value');.
          $account->activate();
          // Save user.
          $account->save();

          // Notify the user that they need to validate their email.
          _user_mail_notify('register_no_approval_required', $account);
          unset($_SESSION['bakery']['register']);
          drupal_set_message(t('A welcome message with further instructions has been sent to your e-mail address.'));
        }
        if (!empty($errors['name'])) {
          drupal_set_message(t('Name is already taken.'), 'error');
        }
        if (!empty($errors['mail'])) {
          drupal_set_message(t('E-mail address is already registered.'), 'error');
        }
        if (!empty($errors['mail_denied'])) {
          drupal_set_message(t('The e-mail address has been denied access..'), 'error');
        }
        if (!empty($errors['name_denied'])) {
          drupal_set_message(t('The name has been denied access..'), 'error');
        }
        // There are errors so keep user on registration page.
        $this->redirect('user.register');
      }
    }
    throw new AccessDeniedHttpException();
  }

  /**
   * Custom return for errors during minion login process.
   */
  public function bakeryLoginReturn() {
    $cookie = $this->bakeryTasteOatmealCookie();
    if ($cookie) {
      // Valid cookie, now destroy it.
      $this->bakery_service->eatCookie('OATMEAL');

      if (!empty($cookie['data']['errors'])) {
        $errors = $cookie['data']['errors'];
        if (!empty($errors['incorrect-credentials'])) {
          \Drupal::messenger()->addError(t('Sorry, unrecognized username or password.'));
        }
        elseif (!empty($errors['name'])) {
          // In case an attacker got the hash we filter the argument
          // here to avoid exposing a XSS vector.
          \Drupal::messenger()->addError(Xss::filter($errors['name']));
        }
      }
      if (empty($cookie['data']['destination'])) {
        return $this->redirect('user.page');
      }
      else {
        return new TrustedRedirectResponse($cookie['data']['destination']);
      }
    }
    throw new AccessDeniedHttpException();
  }

  /**
   * Menu callback, invoked on the minion.
   */
  public function bakeryEatStroopwafelCookie() {
    // The session got set during validation.
    $stroopwafel = $_SESSION['bakery'];
    unset($_SESSION['bakery']);

    $init = $this->bakery_service->initField($stroopwafel['uid']);

    // Check if the user exists.
    $account = \Drupal::entityManager()->getStorage('user')->loadByProperties(array('init' => $init));
    if (empty($account)) {
      // User not present.
      $message = t('Account not found on %minion.', array('%minion' => $this->config('system.site')->get('name')));

    }
    else {
      $account = reset($account);
      drupal_add_http_header('X-Drupal-bakery-UID', $account->id());

      // If profile field is enabled we manually save profile fields along.
      $fields = array();
      foreach ($this->config('bakery.settings')->get('bakery_supported_fields') as $type => $value) {
        if ($value) {
          // If the field is set in the cookie
          // it's being updated, otherwise we'll
          // populate $fields with the existing
          // values so nothing is lost.
          if (isset($stroopwafel[$type])) {
            $fields[$type] = $stroopwafel[$type];
          }
          else {
            $fields[$type] = $account->$type;
          }
        }
      }
      // dpm($account);
      // @FIXME
      // user_save() is now a method of the user entity.
      // $status = user_save($account, $fields);.
      if ($status === FALSE) {
        \Drupal::logger('bakery')
          ->error('User update from name %name_old to %name_new, mail %mail_old to %mail_new failed.', array(
            '%name_old' => $account->getUsername(),
            '%name_new' => $stroopwafel['name'],
            '%mail_old' => $account->getEmail(),
            '%mail_new' => $stroopwafel['mail'],
          ));
        $message = t('There was a problem updating your account on %minion. Please contact the administrator.', array(
          '%minion' => $this->config('system.site')->get('name'),
        ));

        header('HTTP/1.1 409 Conflict');
      }
      else {
        \Drupal::logger('bakery')
          ->notice('user updated name %name_old to %name_new, mail %mail_old to %mail_new.', array(
            '%name_old' => $account->getUsername(),
            '%name_new' => $stroopwafel['name'],
            '%mail_old' => $account->mail,
            '%mail_new' => $stroopwafel['mail'],
          ));
        $message = t('Successfully updated account on %minion.', array(
          '%minion' => $this->config('system.site')->get('name'),
        ));

      }
    }

    $this->moduleHandler()->invokeAll('exit');
    print $message;
    exit();
  }

  /**
   * Save UID provided by a minion site. Should only be used on the main site.
   *
   * @param object $account
   *   A local user object.
   * @param string $minion
   *   The URL of the minion site.
   * @param int $minion_uid
   *   The corresponding UID on the minion site.
   */
  private function bakerySaveMinionUid($account, $minion, $minion_uid) {
    $minion_user_exists = db_query_range("SELECT 1 FROM {bakery_user} WHERE uid = :uid AND minion = :minion", 0, 1, array(
      ':uid' => $account->id(),
      ':minion' => $minion,
    ))->fetchField();
    if ($this->config('bakery.settings')->get('bakery_is_main') &&
        !empty($minion_uid) &&
        in_array($minion, $this->config('bakery.settings')->get('bakery_minions') || array()) &&
        !$minion_user_exists) {
      $row = array(
        'uid' => $account->id(),
        'minion' => $minion,
        'minion_uid' => $minion_uid,
      );
      \Drupal::database()->insert('bakery_user')->fields($row)->execute();
    }
  }

  /**
   * Validate update request.
   */
  public function bakeryTasteStroopwafelCookie() {
    $type = 'stroopwafel';
    if (empty($_POST[$type])) {
      return AccessResult::forbidden();
    }
    if (($payload = $this->bakery_service->validateData($_POST[$type], $type)) === FALSE) {
      return AccessResult::forbidden();
    }

    $_SESSION['bakery'] = unserialize($payload['data']);
    $_SESSION['bakery']['uid'] = $payload['uid'];
    $_SESSION['bakery']['category'] = $payload['category'];
    return AccessResult::allowed();
  }

  /**
   * Only let people with actual problems mess with uncrumble.
   */
  public function bakeryUncrumbleAccess() {
    $user = \Drupal::currentUser();
    $access = AccessResult::forbidden();
    if ($user->id() == 0) {
      if (isset($_SESSION['BAKERY_CRUMBLED']) && $_SESSION['BAKERY_CRUMBLED']) {
        $access = AccessResult::allowed();
      }
    }
    return $access;
  }

  /**
   * Validate the account information request.
   */
  public function bakeryTasteGingerbreadCookie() {
    $type = 'gingerbread';
    if (empty($_POST[$type])) {
      return AccessResult::forbidden();
    }
    if (($cookie = $this->bakery_service->validateData($_POST[$type], $type)) === FALSE) {
      return AccessResult::forbidden();
    }
    $_SESSION['bakery']['name'] = $cookie['name'];
    $_SESSION['bakery']['or_email'] = $cookie['or_email'];
    $_SESSION['bakery']['minion'] = $cookie['minion'];
    $_SESSION['bakery']['uid'] = $cookie['uid'];
    return AccessResult::allowed();
  }

  /**
   * Verify the validation request.
   */
  public function bakeryTasteThinmintCookie() {
    $type = 'thinmint';
    if (empty($_POST[$type])) {
      return AccessResult::forbidden();
    }
    if (($cookie = $this->bakery_service->validateData($_POST[$type], $type)) === FALSE) {
      return AccessResult::forbidden();
    }
    $_SESSION['bakery']['name'] = $cookie['name'];
    $_SESSION['bakery']['minion'] = $cookie['minion'];
    $_SESSION['bakery']['uid'] = $cookie['uid'];
    return AccessResult::allowed();
  }

  /**
   * User is anonymous or not .
   */
  public function userIsAnonymous() {
    if (\Drupal::currentUser()->isAnonymous()) {
      return AccessResult::allowed();
    }
    else {
      return AccessResult::forbidden();
    }
  }

  /**
   * For testing the Cookie.
   */
  private function bakeryTasteOatmealCookie() {
    $key = $this->config('bakery.settings')->get('bakery_key');
    $type = $this->bakery_service->cookieName('OATMEAL');
    if (!isset($_COOKIE[$type]) || !$key || !$this->config('bakery.settings')->get('bakery_domain')) {
      dsm('failed cookie check on oatmeal');
      return FALSE;
    }
    if (($data = $this->bakery_service->validateData($_COOKIE[$type], $type)) !== FALSE) {
      return $data;
    }
    dsm('Data did not validate');
    return FALSE;
  }

}
