<?php

namespace Drupal\custom_example\EventSubscriber;

use Detection\MobileDetect;
use Drupal\custom_example\Service\AnalyticsHelper;
use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Routing\RouteMatchInterface;

class PageTrackerSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The session service.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The base helper service.
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * The analytics helper service
   *
   * @var \Drupal\custom_example\Service\AnalyticsHelper $analyticsHelper
   */
  protected $analyticsHelper;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    Connection $database,
    SessionInterface $session,
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    RouteMatchInterface $route_match,
    BaseHelper $baseHelper,
    AnalyticsHelper $analytics_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_user;
    $this->database = $database;
    $this->session = $session;
    $this->logger = $logger->get('custom_example');
    $this->configFactory = $config_factory;
    $this->routeMatch = $route_match;
    $this->baseHelper = $baseHelper;
    $this->analyticsHelper = $analytics_helper;
  }

  /**
   * Logs unique page visits.
   */
  public function onRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Skip admin routes, ajax, etc.
    if (strpos($path, '/admin') === 0 || $request->isXmlHttpRequest()) {
      return;
    }

    // Get the route name (if routing has matched).
    $route_name = $request->attributes->get('_route');
    $uid = $this->account->id();
    $session_id = $this->session->getId();
    $ip = $request->getClientIp();
    $remote_addr = $request->server->get('REMOTE_ADDR');
    $user_agent = $request->headers->get('User-Agent');
    $accept_language = $request->headers->get('Accept-Language');
    $accept = $request->headers->get('Accept');
    $is_bot = $this->baseHelper->isBot($user_agent, $ip, $accept_language, $accept);

    // Detect the device
    $detect = new MobileDetect();
    $is_mobile = $detect->isMobile();
    $is_tablet = $detect->isTablet();
    $device_type = 1;
    if ($is_tablet) {
      $device_type = 2;
    }
    elseif ($is_mobile) {
      $device_type = 3;
    }

    // Get the node.
    $nid = 0;
    $bundle = '';
    if ($route_name === 'entity.node.canonical') {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $request->attributes->get('node');
      if ($node && in_array($node->bundle(), [
          'archive_descriptions',
          'highlight_upcoming_guest',
          'upcoming_show_topics',
          'article',
          'blog',
          'program_handouts',
          'talk_show_include',
          'pages'
        ])) {
        $nid = $node->id();
        $bundle = $node->bundle();
      }
    }

    $host_uid = 0;
    if ($route_name === 'entity.user.canonical') {
      /** @var \Drupal\user\UserInterface $user */
      $user = $request->attributes->get('user');
      if ($user && in_array('host', $user->getRoles())) {
        $host_uid = $user->id();
      }
    }

    $rss_nid = NULL;
    if ($route_name === 'custom_bbsradio.rss_talkshow') {
      $node_param = $request->attributes->get('node');

      if ($node_param instanceof NodeInterface) {
        $rss_nid = $node_param->id();
      }
      elseif (is_numeric($node_param)) {
        $rss_nid = (int) $node_param;
      }

      if ($rss_nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($rss_nid);
        if ($node) {
          $nid = $node->id();
          $bundle = $node->bundle();
        }
      }
    }

    $mrss_nid = NULL;
    if ($route_name === 'custom_bbsradio.mrss_talkshow') {
      $node_param = $request->attributes->get('node');

      if ($node_param instanceof NodeInterface) {
        $mrss_nid = $node_param->id();
      }
      elseif (is_numeric($node_param)) {
        $mrss_nid = (int) $node_param;
      }

      if ($mrss_nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($mrss_nid);
        if ($node) {
          $nid = $node->id();
          $bundle = $node->bundle();
        }
      }
    }

    // Embedded and copied url
    $audio_video_routes = [
      'bbsradio_mp3_to_mp4.audio_embed',
      'bbsradio_mp3_to_mp4.audio_listen',
      'bbsradio_mp3_to_mp4.video_conv_watch',
      'bbsradio_mp3_to_mp4.video_conv_embed',
      'bbsradio_mp3_to_mp4.video_prod_watch',
      'bbsradio_mp3_to_mp4.video_prod_embed',
      'bbsradio_mp3_to_mp4.video_upld_watch',
      'bbsradio_mp3_to_mp4.video_upld_embed',
    ];

    if (in_array($route_name, $audio_video_routes)) {
      $node_param = $request->attributes->get('node');
      $node = false;
      $nid = 0;
      $bundle = '';
      if ($node_param) {
        $nid = (int) $node_param;
        $node = $this->entityTypeManager->getStorage('node')->load($mrss_nid);
        if ($node) {
          $bundle = $node->bundle();
        }
        else {
          $bundle = '';
        }
      }

      if ($node instanceof \Drupal\node\NodeInterface && $bundle == 'archive_descriptions') {
        $this->database->merge('analytics_node_visits')
          ->fields([
            'session_id' => $session_id,
            'visit_date' => date('Y-m-d'),
            'uid' => $this->account->id(),
            'nid' => $nid,
            'type' => $bundle,
            'ip_address' => $ip,
            'visit_count' => 1,
            'bot' => $is_bot,
            'device_type' => $device_type,
            'timestamp' => \Drupal::time()->getCurrentTime(),
          ])
          ->expression('visit_count', 'visit_count + :inc', [':inc' => 1])
          ->keys([
            'session_id' => $session_id,
            'visit_date' => date('Y-m-d'),
            'nid' => $nid
          ])
          ->execute();
      }
    }

    if ($route_name === 'entity.node.canonical' && $nid && !empty($bundle)) {
      $this->database->merge('analytics_node_visits')
        ->fields([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'uid' => $this->account->id(),
          'nid' => $nid,
          'type' => $bundle,
          'ip_address' => $ip,
          'visit_count' => 1,
          'bot' => $is_bot,
          'device_type' => $device_type,
          'timestamp' => \Drupal::time()->getCurrentTime(),
        ])
        ->expression('visit_count', 'visit_count + :inc', [':inc' => 1])
        ->keys([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'nid' => $nid
        ])
        ->execute();
    }

    if ($route_name === 'entity.user.canonical' && $host_uid) {
      $this->database->merge('analytics_profile_visits')
        ->fields([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'uid' => $this->account->id(),
          'host_uid' => $host_uid,
          'ip_address' => $ip,
          'visit_count' => 1,
          'bot' => $is_bot,
          'device_type' => $device_type,
          'timestamp' => \Drupal::time()->getCurrentTime(),
        ])
        ->expression('visit_count', 'visit_count + :inc', [':inc' => 1])
        ->keys([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'host_uid' => $host_uid
        ])
        ->execute();
    }

    if (($route_name === 'custom_bbsradio.rss_talkshow' || $route_name === 'custom_bbsradio.mrss_talkshow') && ($rss_nid || $mrss_nid)) {
      $this->database->merge('analytics_rss_visits')
        ->fields([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'rss_type' => $rss_nid ? 1 : 2,
          'rss_id' => $rss_nid ? $rss_nid : $mrss_nid,
          'uid' => $this->account->id(),
          'nid' => $nid,
          'type' => $bundle,
          'ip_address' => $ip,
          'visit_count' => 1,
          'bot' => $is_bot,
          'device_type' => $device_type,
          'timestamp' => \Drupal::time()->getCurrentTime(),
        ])
        ->expression('visit_count', 'visit_count + :inc', [':inc' => 1])
        ->keys([
          'session_id' => $session_id,
          'visit_date' => date('Y-m-d'),
          'rss_type' => $rss_nid ? 1 : 2,
          'rss_id' => $rss_nid ? $rss_nid : $mrss_nid,
        ])
        ->execute();
    }

  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }
}
