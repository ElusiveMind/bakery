<?php

/**
 * @file
 * Contains \Drupal\bakery\EventSubscriber\AnonymousLogin.
 */

namespace Drupal\bakery\EventSubscriber;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\bakery\BakeryService;

/**
 * Event subscriber subscribing to KernelEvents::REQUEST.
 */
class AnonymousLogin implements EventSubscriberInterface {

  protected $bakery;

  public function __construct() {
    $this->account = \Drupal::currentUser();
    $this->bakery = new BakeryService();
    $this->db = \Drupal::database();
    \Drupal::service('page_cache_kill_switch')->trigger();
  }

  public function checkAuthStatus(GetResponseEvent $event) {
    if ($this->account->isAnonymous()) {
      if (!empty($_SERVER['HTTP_REFERER'])) {
        $this->bakery->bakeFortuneCookie($_SERVER['HTTP_REFERER']);
      }
      $current_path = \Drupal::service('path.current')->getPath();
      if ($current_path == '/user/login') {
        if (!empty($_ENV['AH_SITE_ENVIRONMENT']) && $_ENV['AH_SITE_ENVIRONMENT'] == 'test') {
          if ($_ENV['SITE'] == 'social') {
            header('location: https://learn-dev.americorps.gov/user/login');
            exit();
          }
        }
        elseif (!empty($_ENV['AH_SITE_ENVIRONMENT']) && $_ENV['AH_SITE_ENVIRONMENT'] == 'uat') {
          if ($_ENV['SITE'] == 'social') {
            header('location: https://learn.americorps.gov/user/login');
            exit();
          }
        }
        else {
          if ($_ENV['SITE'] == 'social') {
            header('location: http://localhost:8091/user/login');
            exit();
          }
        }
      }
    }

    if ($this->account->isAnonymous()) {
      $cookie = $this->bakery->validateCookie();
      // If we have a cookie, we have an account. If the account does not exist
      // on the minion, create it and log that user in
      if (!empty($cookie)) {
        $account = user_load_by_name($cookie['name']);
        if (!empty($account)) {
          user_login_finalize($account);
        }
        else {
          // get the max uid
          $query = $this->db->select('users', 'u');
          $query->addExpression('MAX(uid)');
          $uid = $query->execute()->fetchField() + 1;
          // create user table entry
          $uuid_service = \Drupal::service('uuid');
          $uuid = $uuid_service->generate();
          $this->db->insert('users')
            ->fields([
              'uid' => $uid,
              'uuid' => $uuid,
              'langcode' => 'en', // yeech, but we will come back to this.
              ])
            ->execute();
          
          // Add things to our user data
          $this->db->insert('users_field_data')
            ->fields([
              'uid' => $uid,
              'langcode' => 'en',
              'preferred_langcode' => 'en',
              'preferred_admin_langcode' => 'en',
              'name' => $cookie['name'],
              'pass' => $cookie['pass'],
              'mail' => $cookie['mail'],
              'timezone' => 'America/New_York',
              'access' => 0,
              'login' => 0,
              'status' => 1,
              'created' => time(),
              'changed' => time(),
              'init' => 'localhost:8091/user/'.$uid.'/edit',
              'default_langcode' => 1,
            ])
            ->execute();

          // vistausers on open social and opigno
          $this->db->insert('user__roles')
            ->fields([
              'bundle' => 'user',
              'deleted' => 0,
              'entity_id' => $uid,
              'revision_id' => $uid,
              'langcode' => 'en',
              'delta' => 0,
              'roles_target_id' => 'vistausers',
            ])
            ->execute();

          $this->db->insert('users_data')
            ->fields([
              'uid' => $uid,
              'module' => 'contact',
              'name' => 'enabled',
              'value' => 1,
              'serialized' => 0,
            ])
            ->execute();
          
          $account = \Drupal\user\Entity\User::load($uid);
          user_login_finalize($account);
        }
      }
    }
    else {
      $cookie = $this->bakery->validateCookie();
      // If we have a cookie, carry on. Otherwise kill the session.
      if (empty($cookie)) {
        $cookie_secure = ini_get('session.cookie_secure');
        $type = $this->cookieName(session_name());
        setcookie($type, '', $_SERVER['REQUEST_TIME'] - 3600, '/', '', (empty($cookie_secure) ? FALSE : TRUE));    
      }
      $this->bakery->eatCookie('FORTUNE');
    }
  }

  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('checkAuthStatus', 30);
    return $events;
  }

  public function cookieName($type = 'CHOCOLATECHIP') {
    // Use different names for HTTPS and HTTP to prevent a cookie collision.
    if (ini_get('session.cookie_secure')) {
      $type .= 'SSL';
    }

    return $type;
  }

}