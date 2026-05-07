<?php
/**
 * @file
 * contains \Drupal\custom_example\Controller\AnalyticsSimpleDashboard.
 */

namespace Drupal\custom_example\Controller;

use Drupal\custom_example\Service\AnalyticsHelper;
use Drupal\custom_example\Service\DashboardHelper;
use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\statistics\NodeStatisticsDatabaseStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AnalyticsSimpleDashboardController extends ControllerBase {

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
   * Path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $pathAliasManager;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The Statistics service.
   *
   * @var \Drupal\statistics\NodeStatisticsDatabaseStorage
   */
  protected $statisticsStorage;

  /**
   * The base helper service
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper $baseHelper
   */
  protected $baseHelper;

  /**
   * The dashboard helper service
   *
   * @var \Drupal\custom_example\Service\DashboardHelper $dashboardHelper
   */
  protected $dashboardHelper;

  /**
   * The analytics helper service
   *
   * @var \Drupal\custom_example\Service\AnalyticsHelper $analyticsHelper
   */
  protected $analyticsHelper;

  /**
   * Constructor
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param AccountInterface $current_account
   * @param Connection $database
   * @param AliasManagerInterface $path_alias_manager
   * @param LanguageManagerInterface $language_manager
   * @param NodeStatisticsDatabaseStorage $statistics_storage
   * @param BaseHelper $base_helper
   * @param DashboardHelper $dashboard_helper
   * @param AnalyticsHelper $analytics_helper
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    AliasManagerInterface $path_alias_manager,
    LanguageManagerInterface $language_manager,
    NodeStatisticsDatabaseStorage $statistics_storage,
    BaseHelper $base_helper,
    DashboardHelper $dashboard_helper,
    AnalyticsHelper $analytics_helper,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->pathAliasManager = $path_alias_manager;
    $this->languageManager = $language_manager;
    $this->statisticsStorage = $statistics_storage;
    $this->baseHelper = $base_helper;
    $this->dashboardHelper = $dashboard_helper;
    $this->analyticsHelper = $analytics_helper;

    $this->formBuilder = $this->formBuilder();
  }

  /**
   * Creates a new Controller.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new Controller object.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('path_alias.manager'),
      $container->get('language_manager'),
      $container->get('statistics.storage.node'),
      $container->get('base.helper'),
      $container->get('dashboard.helper'),
      $container->get('analytics.helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function analyticsDashboard(Request $request) {
    $analytics_host_uid = $request->getSession()->get('analytics_host_uid', '');
    $analytics_talkshow_nid = $request->getSession()->get('analytics_talkshow_nid', '');

    // List all talk show analytics
    $query = $this->database->select('node_field_data', 'n')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->element(0)
      ->limit(2);
    $query->innerJoin('node__field_show_on_dashboard', 'nfsod', 'n.nid = nfsod.entity_id AND nfsod.deleted = 0 ');
    $query->fields('n', ['nid','title']);
    $query->condition('n.type', 'talk_show_include', '=');
    $query->condition('n.status', 1, '=');
    $query->condition('nfsod.field_show_on_dashboard_value', 1, '=');
    $query->orderBy('n.created', 'DESC');

    if (!empty($analytics_host_uid)) {
      $host_uid = $this->baseHelper->extractId($analytics_host_uid);
      $query->innerJoin('node__field_include_host_name', 'nfihn', "n.nid = nfihn.entity_id AND nfihn.deleted = 0 ");
      $query->condition('nfihn.field_include_host_name_target_id', $host_uid , '=');
    }

    if (!empty($analytics_talkshow_nid)) {
      $query->condition('n.nid', $analytics_talkshow_nid , '=');
    }

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->entityTypeManager->getStorage('node')->load($row->nid);
      $show_page_url = $node->hasField('field_include_show_page') && !$node->get('field_include_show_page')->isEmpty() ?
        $node->field_include_show_page->first()->getUrl()->toString() : '';
      $host_uid = $node->getOwnerId();
      $talk_show_nid = $node->id();
      $talk_show_title = $node->getTitle();
      $rss_feed = $node->hasField('field_include_show_feed') && !$node->get('field_include_show_feed')->isEmpty() ?
        $node->field_include_show_feed->first()->getUrl()->toString() : '';
      $mrss_feed = $node->hasField('field_include_mrss_feed') && !$node->get('field_include_mrss_feed')->isEmpty() ?
        $node->field_include_mrss_feed->first()->getUrl()->toString() : '';
      $include_hosts = array_column($node->get('field_include_host_name')->getValue(), 'target_id');
      $interval = '';

      // Get the summary content
      $summary_contents_data = $this->analyticsHelper->analyticsSummaryContent($talk_show_nid, $include_hosts, $rss_feed, $mrss_feed, $show_page_url, $interval);

      // Live listener statistics content
      $listener_stats_content = $this->analyticsHelper->listenerStatisticsContent($show_page_url);

      // More statistics content
      //$more_stats_content = $this->analyticsHelper->analyticsMoreStatistics($talk_show_nid);

      $summary_build = [
        '#theme' => 'custom_example_summary',
        '#talk_show_nid' => $row->nid,
        '#talk_show_title' => $talk_show_title,
        '#summary_contents_data' => $summary_contents_data,
        '#listener_stats_content' => Markup::create($listener_stats_content),
        //'#more_statistics_content' => $more_stats_content,
      ];

      $rows[] = $summary_build;
    }


    $header  = '<div class="analytics-header-wrapper">';
    $header .= '  <div class="analytics-header-title">';
    $header .= '    <h2 class="header-title">Analytics Dashboard</h2>';
    $header .= '  </div>';
    $header .= '</div>';
    $build['header'] = [
      '#markup' => Markup::create($header),
    ];

    // Filter form
    $build['filter'] = $this->formBuilder->getForm('Drupal\custom_example\Form\AnalyticsReportFilterForm', 0);

    // Theme the html table
    if (!empty($rows)) {
      $build['list'] = [
        '#theme' => 'container',
        '#children' => $rows,
        '#attributes' => ['width' => '100%', 'id' => 'analytics-list', 'class' => ['analytics-list']],
      ];

      $build['pager'] = [
        '#type' => 'pager',
        '#element' => 0,
        '#weight' => 10,
        '#query_string_handling' => 'drop',
      ];
    }
    else {
      $build['list'] = [
        '#markup' => '<p>Sorry! No data available</p>',
      ];
    }

    // Don't cache this page.
    $build['#cache']['max-age'] = 0;
    $build['#attached']['library'][] = 'bbsradio_base/base_style';

    return $build;
  }


  /**
   * {@inheritdoc}
   */
  public function analyticsHostDashboard(Request $request) {
    $analytics_talkshow_nid = $request->getSession()->get('analytics_talkshow_nid', '');

    // List all talk show analytics
    $query = $this->database->select('node_field_data', 'n')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->element(0)
      ->limit(2);
    $query->innerJoin('node__field_show_on_dashboard', 'nfsod', 'n.nid = nfsod.entity_id AND nfsod.deleted = 0 ');
    $query->fields('n', ['nid','title']);
    $query->condition('n.type', 'talk_show_include', '=');
    $query->condition('n.status', 1, '=');
    $query->condition('nfsod.field_show_on_dashboard_value', 1, '=');
    $query->orderBy('n.created', 'DESC');

    // Filter by host i.e. the current user
    $host_uid = $this->account->id();
    $query->innerJoin('node__field_include_host_name', 'nfihn', "n.nid = nfihn.entity_id AND nfihn.deleted = 0 ");
    $query->condition('nfihn.field_include_host_name_target_id', $host_uid , '=');

    if (!empty($analytics_talkshow_nid)) {
      $query->condition('n.nid', $analytics_talkshow_nid , '=');
    }

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->entityTypeManager->getStorage('node')->load($row->nid);
      $show_page_url = $node->hasField('field_include_show_page') && !$node->get('field_include_show_page')->isEmpty() ?
        $node->field_include_show_page->first()->getUrl()->toString() : '';
      $host_uid = $node->getOwnerId();
      $talk_show_nid = $node->id();
      $talk_show_title = $node->getTitle();
      $rss_feed = $node->hasField('field_include_show_feed') && !$node->get('field_include_show_feed')->isEmpty() ?
        $node->field_include_show_feed->first()->getUrl()->toString() : '';
      $mrss_feed = $node->hasField('field_include_mrss_feed') && !$node->get('field_include_mrss_feed')->isEmpty() ?
        $node->field_include_mrss_feed->first()->getUrl()->toString() : '';
      $include_hosts = array_column($node->get('field_include_host_name')->getValue(), 'target_id');
      $interval = '';

      // Get the summary content
      $summary_contents_data = $this->analyticsHelper->analyticsSummaryContent($talk_show_nid, $include_hosts, $rss_feed, $mrss_feed, $show_page_url, $interval);

      // Live listener statistics content
      $listener_stats_content = $this->analyticsHelper->listenerStatisticsContent($show_page_url);

      // More statistics content
      // $more_stats_content = $this->analyticsHelper->analyticsMoreStatistics($talk_show_nid);

      $summary_build = [
        '#theme' => 'custom_example_summary',
        '#talk_show_nid' => $row->nid,
        '#talk_show_title' => $talk_show_title,
        '#summary_contents_data' => $summary_contents_data,
        '#listener_stats_content' => Markup::create($listener_stats_content),
        //'#more_statistics_content' => $more_stats_content,
      ];

      $rows[] = $summary_build;
    }

    $header  = '<div class="analytics-header-wrapper">';
    $header .= '  <div class="analytics-header-title">';
    $header .= '    <h2 class="header-title">Analytics Host Dashboard</h2>';
    $header .= '  </div>';
    $header .= '</div>';
    $build['header'] = [
      '#markup' => Markup::create($header),
    ];

    // Filter form
    $build['filter'] = $this->formBuilder->getForm('Drupal\custom_example\Form\AnalyticsReportFilterForm', 1);

    // Theme the html table
    if (!empty($rows)) {
      $build['list'] = [
        '#theme' => 'container',
        '#children' => $rows,
        '#attributes' => ['width' => '100%', 'id' => 'analytics-list', 'class' => ['analytics-list']],
      ];

      $build['pager'] = [
        '#type' => 'pager',
        '#element' => 0,
        '#weight' => 10,
      ];
    }
    else {
      $build['list'] = [
        '#markup' => '<p>Sorry! No data available</p>',
      ];
    }

    // Don't cache this page.
    $build['#cache']['max-age'] = 0;
    $build['#attached']['library'][] = 'bbsradio_base/base_style';

    return $build;
  }

}
