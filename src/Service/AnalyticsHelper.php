<?php

namespace Drupal\custom_example\Service;

use Drupal\centova_livestats\Form\LivestatsSettingsForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\Renderer;

/**
 * Analytics Helper Class.
 *
 * @package Drupal\custom_example
 */
class AnalyticsHelper {
  use StringTranslationTrait;

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
   * The render service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * The Analytics columns.
   *
   * @var \Drupal\custom_example\Service\AnalyticsColumns $analyticsColumns
   */
  protected $analyticsColumns;

  /**
   * The dashboard helper service.
   *
   * @var \Drupal\custom_example\Service\DashboardHelper $dashboardHelper
   */
  protected $dashboardHelper;

  private static int $pagerCounter = 1000000;

  /**
   * Create AnalyticsHelper constructor
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param AccountInterface $current_account
   * @param Connection $database
   * @param LoggerChannelFactoryInterface $logger
   * @param ConfigFactoryInterface $config_factory
   * @param AliasManagerInterface $path_alias_manager
   * @param LanguageManagerInterface $language_manager
   * @param DashboardHelper $dashboard_helper
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $path_alias_manager,
    LanguageManagerInterface $language_manager,
    Renderer $renderer,
    DashboardHelper $dashboard_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('dashboard');
    $this->configFactory = $config_factory;
    $this->pathAliasManager = $path_alias_manager;
    $this->languageManager = $language_manager;
    $this->renderer = $renderer;
    $this->dashboardHelper = $dashboard_helper;

    $this->analyticsColumns = new AnalyticsColumns();
  }

  /**
   * Prepare airing time
   *
   * @param $start_time
   * @param $end_time
   * @param $last_entry
   * @param $current_datetime
   * @return string
   */
  function prepareAiringTime($start_time, $end_time, $last_entry, $current_datetime = NULL) {
    $timestamp_data = !empty($current_datetime) ? strtotime($current_datetime) : time();
    $show_start_time = str_replace(' PT', '', $start_time);
    $show_start_time  = strtotime(date('Y-m-d ', $last_entry) . $show_start_time);
    $show_end_time = str_replace(' PT', '', $end_time);
    $show_end_time  = strtotime(date('Y-m-d ', $last_entry) . $show_end_time);
    $current_time = $timestamp_data;

    if (($current_time >= $show_start_time) && ($current_time <= $show_end_time)) {
      $time = ' ' . $start_time . ' - airing';
    }
    else {
      $time = ' ' . $start_time . ' - ' . $end_time;
    }

    return $time;
  }

  /**
   * {@inheritdoc}
   */
  public function listenerStatisticsContent(string $profile_url) {
    // Prepare the condition
    $show_name_url = 'internal:/' . $profile_url;
    $show_name_url_cond = '%' . $show_name_url . '%';

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n');
    $query->leftjoin('node__field_show_broadcast_schedule', 'sbs', 'n.nid = sbs.entity_id');
    $query->leftjoin('paragraph__field_schedule_starts', 'ss', 'sbs.field_show_broadcast_schedule_target_id = ss.entity_id');
    $query->leftjoin('paragraph__field_schedule_ends', 'sends', 'sbs.field_show_broadcast_schedule_target_id = sends.entity_id');
    $query->leftjoin('paragraph__field_schedule_broadcast_day', 'sbd', 'sbs.field_show_broadcast_schedule_target_id = sbd.entity_id');
    $query->leftjoin('node__field_include_show_page', 'isp', 'n.nid = isp.entity_id');
    $query->condition('n.type', 'talk_show_include');
    $query->condition('isp.field_include_show_page_uri', $show_name_url_cond, 'LIKE');
    $results = $query->execute()->fetchAll();

    $scheduled_days = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $end_time = '';
    $start_time = '';
    foreach ($results as $key => $row) {
      $node = $node_storage->load($row->nid);

      $paragraph = null;
      foreach ($node->get('field_show_broadcast_schedule') as $p) {
        if ($p->entity->getType() == 'show_broadcast_schedule') {
          $paragraph = $p->entity;
          break;
        }
      }

      if ($paragraph) {
        $end_time = $paragraph->get('field_schedule_ends')->value;
        $start_time = $paragraph->get('field_schedule_starts')->value;
        $scheduled_days[$key] = $paragraph->get('field_schedule_broadcast_day')->value;
      }
    }

    $query = $this->database->select('centova', 'c')
      ->fields('c', ['time', 'created'])
      ->condition('c.showname', $show_name_url, '=')
      ->orderBy('created', 'DESC');
    $time_result = $query->execute()->fetchAssoc();
    $time = $this->prepareAiringTime($start_time, $end_time, isset($time_result['created']) ?? NULL);

    $query = $this->database->select('node__field_include_show_page', 'fdfisp')
      ->fields('fdfisp',['field_include_show_page_title']);
    $query->join('node__field_show_on_dashboard', 'fdfsod', 'fdfsod.entity_id=fdfisp.entity_id');
    $query->condition('fdfisp.field_include_show_page_uri', $show_name_url, '=');
    $query->condition('fdfsod.field_show_on_dashboard_value', 1, '=');
    $show_name_title = $query->execute()->fetchField();

    if ($show_name_url == 'spirituallyraw') {
      $show_name_url = 'spirituallyrawtheasswhippingtruth';
    }
    elseif ($show_name_url == 'sii') {
      $show_name_url = 'suddeniimpact';
    }
    elseif ($show_name_url == 'allroads') {
      $show_name_url = 'allroadslead65maxradio';
    }
    elseif ($show_name_url == 'everydaypeace') {
      $show_name_url = 'everydaypeacewithdrdravonjames';
    }
    elseif ($show_name_url == 'drgina') {
      $show_name_url = 'drginasradiochat';
    }
    elseif ($show_name_url == 'whylifeis') {
      $show_name_url = 'whylifeis...';
    }
    elseif ($show_name_url == 'spirituallyraw') {
      $show_name_url = 'spirituallyrawtheasswhippingtruth';
    }

    if (isset($scheduled_days[1]) && $scheduled_days[1]) {
      $ts_query = $this->database->select('centova', 'c')
        ->fields('c')
        ->condition('c.showname', $show_name_url, '=')
        ->orderBy("created", 'DESC');
      $ts_result = $ts_query->execute()->fetchAssoc();

      $begin_of_day = strtotime('today', $ts_result['created']);
      $end_of_day   = strtotime('tomorrow', $begin_of_day) - 1;
      $get_date = date('d-m-Y', $ts_result['created']);
      $display_days = $ts_result['day'];
    }
    else {
      $display_days = $scheduled_days[0] ?? '';
      $ts_query = $this->database->select('centova', 'c')
        ->fields('c')
        ->condition('c.showname', $show_name_url, '=')
        ->orderBy("created", 'DESC');
      $ts_result = $ts_query->execute()->fetchAssoc();

      $begin_of_day = '';
      $end_of_day = '';
      $get_date = '';
      if ($ts_result) {
        $begin_of_day = strtotime('today', $ts_result['created']);
        $end_of_day = strtotime("tomorrow", $begin_of_day) - 1;
        $get_date = date('d-m-Y', $ts_result['created']);
      }
    }

    $output = '<div class="live-listener-statistics-data-wrapper">';
    $output .= '<table width="100%" class="individual-data-table">';
    $output .= '<tr>';
    $output .= '  <th class="dash-row-head1">Internet Stream</th>';
    $output .= '  <th class="dash-row-head1">Title</th>';
    $output .= '  <th class="dash-row-head1">Pulls this month</th>';
    $output .= '  <th class="dash-row-head1"> Latest show ('. $get_date. ') Show Pulls Only</th>';
    $output .= '</tr>';

    if (empty($show_name_title)) {
      $output .= '<tr>';
      $output .= '  <td colspan="4">Sorry, No data available.</td>';
      $output .= '<tr>';
    }
    else {

      $types = [
        //'AUDIO',
        //'VIDEO',
        '128kbps'
      ];

      $time_month_start_timestamp = strtotime(date('Y-m-01 00:00:00'));
      $time_month_last_timestamp = strtotime(date('Y-m-t 23:59:59'));

      foreach ($types as $type) {
        $output .= '  <tr>';
        $output .= '    <td class="dash-row-odd">' . $type . '</td>';
        $output .= '    <td class="dash-row-odd">' . $show_name_title . '(' . $display_days . ' ' . $time . ')</td>';

        $pull_count_this_month =  $this->database->select('centova', 'c')->fields('c')
          ->condition('bitrate', $type, '=')
          ->condition('showname', $show_name_url, '=')
          ->condition('created', $time_month_start_timestamp, '>')
          ->condition('created', $time_month_last_timestamp, '<')
          ->execute()
          ->fetchAll();

        $pull_count_last_show =  $this->database->select('centova', 'c')->fields('c')
          ->condition('bitrate', $type, '=')
          ->condition('showname', $show_name_url, '=')
          ->condition('created', $begin_of_day, '>')
          ->condition('created', $end_of_day, '<')
          ->execute()
          ->fetchAll();

        $count = 0;
        $count_day = 0;

        foreach ($pull_count_this_month as $value) {
          $count = $count + $value->listeners;
        }

        foreach ($pull_count_last_show as $value) {
          $count_day = $count_day + $value->listeners;
        }

        $output .= '    <td class="dash-row-odd">' . $count . '</td>';
        $output .= '    <td class="dash-row-odd">' . $count_day . '</td>';
        $output .= '  </tr>';
      }
    }

    $output .= '</table>';
    $output .= '</div>';

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function analyticsSummaryContent(int $talk_show_nid, array $include_hosts, string $rss_feed, string $mrss_feed, string $show_page_url, $interval) {
    // If Show page url is empty, return
    if (empty($show_page_url)) {
      return '';
    }

    // Three-month data
    $months_data = $this->getMonthsInfo();
    $months_output = [];

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($talk_show_nid);
    $node_author_id = $node->getOwnerId();

    foreach ($months_data as $key => $month_data) {
      $output = '<table class="dashboard-count-info">';
      $output .= '<tr>';
      $output .= '  <th class="header-col header-col1">Title</th>';
      $output .= '  <th class="header-col header-col1">Page Views</th>';
      $output .= '  <th class="header-col header-col1">Unique Visitors</th>';
      $output .= '</tr>';

      // Show page
      $output .= $this->getShowPageContent($talk_show_nid, $show_page_url, $month_data);

      // Host Page
      $output .= $this->getHostPageContent($node_author_id, $include_hosts, $month_data);

      // RSS Feed
      $output .= $this->getRSSFeedPageContent($talk_show_nid, $rss_feed, $month_data);

      // MRSS feed
      $output .= $this->getMRSSFeedPageContent($talk_show_nid, $mrss_feed, $month_data);

      $output .= '</table>';

      // More statistics content
      $more_stats_content = $this->analyticsMoreStatistics($talk_show_nid, $month_data);

      // Add the output to the array
      $months_output[$key]['output'] = $output;
      $months_output[$key]['more_output'] = $more_stats_content;
      $months_output[$key]['context'] = $month_data;
    }

    return $months_output;
  }

  /**
   * Get Analytics Dashboard Page Header / Columns
   *
   * @param $interval
   * @return string
   */
  private function getPageHeaderContent($interval, $month_data) {
    $header = '<tr>';
    $header .= '  <th class="header-col header-col1">Title</th>';
    $header .= '  <th class="header-col header-col1">Page Views</th>';
    $header .= '  <th class="header-col header-col1">Unique Visitors</th>';
    $header .= '</tr>';

    return $header;
  }


  /**
   * Get talk show analytics content
   *
   * @param int $talk_show_nid
   * @param string $profile_url
   * @return string
   */
  private function getShowPageContent(int $talk_show_nid, string $show_page_url, $month_data) {
    /** @var \Drupal\node\NodeInterface $talk_show_node */
    $talk_show_node = $this->entityTypeManager->getStorage('node')->load($talk_show_nid);
    $talk_show_path = "/node/$talk_show_nid";
    $talk_show_alias = $this->pathAliasManager->getAliasByPath($talk_show_path);
    $talk_show_url = $talk_show_alias ?: $talk_show_path;

    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    // Get the show page nid
    $internal_path = $this->pathAliasManager->getPathByAlias($show_page_url);
    $show_page_nid = 0;
    if (preg_match('/^\/node\/(\d+)$/', $internal_path, $matches)) {
      $show_page_nid = $matches[1];
    }

    // Talk show page views and unique visitors
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->condition('anv.nid', $talk_show_nid);
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('SUM(anv.visit_count)', 'total_visit_count');
    $talk_show_page_views = $query->execute()->fetchField();

    if (empty($talk_show_page_views)) {
      $talk_show_page_views = 0;
    }

    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->condition('anv.nid', $talk_show_nid);
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
    $talk_show_page_unique_visitors = $query->execute()->fetchField();

    if (empty($talk_show_page_unique_visitors)) {
      $talk_show_page_unique_visitors = 0;
    }

    $output = '<tr>';
    $output .= '  <td class="talk-show-title"><a href="' . $talk_show_url . '">' . $talk_show_node->getTitle() . '</a></td>';
    $output .= '  <td class="talk-show-visits">' . $talk_show_page_views . '</td>';;
    $output .= '  <td class="talk-show-visitors">' . $talk_show_page_unique_visitors . '</td>';;
    $output .= '</tr>';

    // Show page views and unique visitors
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->condition('anv.nid', $show_page_nid);
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('SUM(anv.visit_count)', 'total_visit_count');
    $show_page_views = $query->execute()->fetchField();

    if (empty($show_page_views)) {
      $show_page_views = 0;
    }

    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->condition('anv.nid', $show_page_nid);
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
    $show_page_unique_visitors = $query->execute()->fetchField();

    if (empty($show_page_unique_visitors)) {
      $show_page_unique_visitors = 0;
    }

    $output .= '<tr>';
    $output .= '  <td class="show-page-title"><a href="' . $show_page_url . '">Show Page</a></td>';
    $output .= '  <td class="show-page-visits">' . $show_page_views . '</td>';;
    $output .= '  <td class="show-page-visitors">' . $show_page_unique_visitors . '</td>';;
    $output .= '</tr>';

    return $output;
  }

  /**
   * Get talk show Host(s) analytics content
   *
   * @param int $node_author_id
   * @param array $include_hosts
   * @return string
   */
  private function getHostPageContent(int $node_author_id, array $include_hosts, $month_data) {
    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $output = '';
    if (!empty($include_hosts)) {
      foreach ($include_hosts as $host_uid) {
        /** @var \Drupal\user\UserInterface $include_host */
        $include_host = $this->entityTypeManager->getStorage('user')->load($host_uid);
        $full_name = $include_host->hasField('field_user_full_name') && !$include_host->get('field_user_full_name')->isEmpty() ?
          $include_host->get('field_user_full_name')->value : $include_host->getAccountName();

        $host_path = '/user/' . $host_uid;
        $host_alias = $this->pathAliasManager->getPathByAlias($host_path);
        $host_url = $host_alias ?: $host_path;

        if ($host_uid == $node_author_id) {
          $host_label = 'Main Host: <a href="' . $host_url . '">' . $full_name . '</a>';;
        }
        else {
          $host_label = 'CoHost: <a href="' . $host_url . '">' . $full_name . '</a>';;
        }

        // Host page views and unique visitors
        $query = $this->database->select('analytics_profile_visits', 'apv');
        $query->condition('apv.host_uid', $host_uid);
        $query->condition('apv.visit_date', [$start, $end], 'BETWEEN');
        $query->addExpression('SUM(apv.visit_count)', 'total_visit_count');
        $host_page_views = $query->execute()->fetchField();

        if (empty($host_page_views)) {
          $host_page_views = 0;
        }

        $query = $this->database->select('analytics_profile_visits', 'apv');
        $query->condition('apv.host_uid', $host_uid);
        $query->condition('apv.visit_date', [$start, $end], 'BETWEEN');
        $query->addExpression('COUNT(DISTINCT apv.ip_address)', 'unique_visitors');
        $host_page_unique_visitors = $query->execute()->fetchField();

        if (empty($host_page_unique_visitors)) {
          $host_page_unique_visitors = 0;
        }

        $output .= '<tr>';
        $output .= '  <td class="host-page-wrapper">' . $host_label . '</td>';
        $output .= '  <td class="host-page-visits">' . $host_page_views . '</td>';;
        $output .= '  <td class="host-page-visitors">' . $host_page_unique_visitors . '</td>';;
        $output .= '</tr>';
      }
    }

    return $output;
  }

  /**
   * Get RSS Feed analytics content
   *
   * @param int $talk_show_nid
   * @param string $rss_feed
   * @return string
   */
  private function getRSSFeedPageContent(int $talk_show_nid, string $rss_feed, $month_data) {
    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $rss_id = 0;
    if (preg_match('/\/customshow(?:\/mrss)?\/(\d+)/', $rss_feed, $matches)) {
      $rss_id = $matches[1];   // 277170
    }

    if (!empty($rss_feed)) {
      $rss_feed_label = 'RSS Feed <a href="' . $rss_feed . '"> ' . ltrim($rss_feed, '/') . ' </a>';
    }
    else {
      $rss_feed_label = 'RSS Feed';
    }

    // RSS page views and unique visitors
    $query = $this->database->select('analytics_rss_visits', 'arv');
    $query->condition('arv.rss_id', $rss_id);
    $query->condition('arv.rss_type', 1);
    $query->condition('arv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('SUM(arv.visit_count)', 'total_visit_count');
    $rss_page_views = $query->execute()->fetchField();

    if (empty($rss_page_views)) {
      $rss_page_views = 0;
    }

    $query = $this->database->select('analytics_rss_visits', 'arv');
    $query->condition('arv.rss_id', $rss_id);
    $query->condition('arv.rss_type', 1);
    $query->condition('arv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT arv.ip_address)', 'unique_visitors');
    $rss_page_unique_visitors = $query->execute()->fetchField();

    if (empty($rss_page_unique_visitors)) {
      $rss_page_unique_visitors = 0;
    }

    $output = '<tr>';
    $output .= '  <td class="rss-feed-wrapper">' . $rss_feed_label . '</td>';
    $output .= '  <td class="rss-feed-visits">' . $rss_page_views . '</td>';;
    $output .= '  <td class="rss-feed-visitors">' . $rss_page_unique_visitors . '</td>';;
    $output .= '</tr>';

    return  $output;
  }

  /**
   * Get MRSS Feed analytics content
   *
   * @param int $talk_show_nid
   * @param string $mrss_feed
   * @return string
   */
  private function getMRSSFeedPageContent(int $talk_show_nid, string $mrss_feed, $month_data) {
    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $rss_id = 0;
    if (preg_match('/\/customshow(?:\/mrss)?\/(\d+)/', $mrss_feed, $matches)) {
      $rss_id = $matches[1];   // 277170
    }

    if (!empty($mrss_feed)) {
      $mrss_feed_label = 'MRSS Feed <a href="' . $mrss_feed . '"> ' . ltrim($mrss_feed, '/') . ' </a>';
    }
    else {
      $mrss_feed_label = 'MRSS Feed';
    }

    // MRSS page views and unique visitors
    $query = $this->database->select('analytics_rss_visits', 'arv');
    $query->condition('arv.rss_id', $rss_id);
    $query->condition('arv.rss_type', 2);
    $query->condition('arv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('SUM(arv.visit_count)', 'total_visit_count');
    $mrss_page_views = $query->execute()->fetchField();

    if (empty($mrss_page_views)) {
      $mrss_page_views = 0;
    }

    $query = $this->database->select('analytics_rss_visits', 'arv');
    $query->condition('arv.rss_id', $rss_id);
    $query->condition('arv.rss_type', 1);
    $query->condition('arv.visit_date', [$start, $end], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT arv.ip_address)', 'unique_visitors');
    $mrss_page_unique_visitors = $query->execute()->fetchField();

    if (empty($mrss_page_unique_visitors)) {
      $mrss_page_unique_visitors = 0;
    }

    $output = '<tr>';
    $output .= '  <td class="mrss-feed-wrapper">' . $mrss_feed_label . '</td>';
    $output .= '  <td class="mrss-feed-visits">' . $mrss_page_views . '</td>';;
    $output .= '  <td class="mrss-feed-visitors">' . $mrss_page_unique_visitors . '</td>';;
    $output .= '</tr>';

    return  $output;
  }

  /**
   * Get more statistics
   *
   * @param int $talk_show_nid
   * @return array|string
   */
  public function analyticsMoreStatistics(int $talk_show_nid, $month_data) {
    // Three-month data
    $months_key = $month_data['month_key'] ?? 'month_0';
    $months_output = [];

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($talk_show_nid);
    $show_page_url = $node->hasField('field_include_show_page') && !$node->get('field_include_show_page')->isEmpty() ?
      $node->field_include_show_page->first()->getUrl()->toString() : '';
    $host_uid = $node->getOwnerId();
    $talk_show_title = $node->getTitle();

    $months_output['blog_content'] = $this->getMoreBlogContent($host_uid, $month_data);
    $months_output['featured_columns_content'] = $this->getFeaturedColumnsContent($host_uid, $month_data);
    $months_output['headlined_show_content'] = $this->getHeadlinedShowsContent($talk_show_nid, $show_page_url, $month_data);
    $months_output['featured_guests_content'] = $this->getFeaturedGuestContent($talk_show_nid, $show_page_url, $month_data);
    $months_output['program_archives_content'] = $this->getProgramArchivesContent($talk_show_nid, $show_page_url, $month_data);

    $build = [
      '#theme' => 'custom_example_more_info',
      '#talk_show_nid' => $talk_show_nid,
      '#talk_show_title' => $talk_show_title,
      '#month_key' => $months_key,
      '#blog_contents_data' => $months_output['blog_content'],
      '#featured_columns_contents_data' => $months_output['featured_columns_content'],
      '#headlined_shows_contents_data' => $months_output['headlined_show_content'],
      '#featured_guests_contents_data' => $months_output['featured_guests_content'],
      '#program_archives_contents_data' => $months_output['program_archives_content'],
    ];

    return $this->renderer->render($build);
  }

  public function getMoreBlogContent(int $host_uid, $month_data) {
    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $header = [
      ['data' => $this->t('Title'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Page Views'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Unique Visitors'), 'attributes' => ['width' => '33.3333%']],
    ];

    // List
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->innerJoin('node_field_data', 'n', 'anv.nid = n.nid');;
    $query->fields('n', ['nid','title']);
    $query->condition('n.uid', $host_uid, '=');
    $query->condition('n.type', 'blog', '=');
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->groupBy('n.nid');
    $query->orderBy('n.created', 'DESC');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $nid = $row->nid;
      $title = $row->title;
      $node_path = '/node/' . $nid;
      $node_alias = $this->pathAliasManager->getAliasByPath($node_path);

      // Page views and unique visitors
      $query_v = $this->database->select('analytics_node_visits', 'anv');
      $query_v->condition('anv.nid', $nid);
      $query_v->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_v->addExpression('SUM(anv.visit_count)', 'total_visit_count');
      $page_views = $query_v->execute()->fetchField();

      if (empty($page_views)) {
        $page_views = 0;
      }

      $query_u = $this->database->select('analytics_node_visits', 'anv');
      $query_u->condition('anv.nid', $nid);
      $query_u->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_u->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
      $page_unique_visitors = $query_u->execute()->fetchField();

      if (empty($page_unique_visitors)) {
        $page_unique_visitors = 0;
      }

      $rows[] = [
        'data' => [
          ['data' => Markup::create('<a href="' . $node_alias . '">' . $title . '</a>'), 'class' => 'title'],
          ['data' => $page_views, 'class' => 'page-views'],
          ['data' => $page_unique_visitors, 'class' => 'unique-visitors'],
        ],
        'id' => 'blog-hits-' . $row->nid,
      ];
    }

    $build_container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['blog-month-container', 'month-' . $month_data['month_no'] . '-' . $month_data['year']]],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#empty' => $this->t('Sorry. No data available.'),
        '#attributes' => ['class' => ['blog-hits-list']],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return [
      'output' => $build_container,
      'context' => $month_data,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function getFeaturedColumnsContent(int $host_uid, $month_data) {
    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $header = [
      ['data' => $this->t('Title'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Page Views'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Unique Visitors'), 'attributes' => ['width' => '33.3333%']],
    ];

    // List
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->innerJoin('node_field_data', 'n', 'anv.nid = n.nid');;
    $query->fields('n', ['nid','title']);
    $query->condition('n.uid', $host_uid, '=');
    $query->condition('n.type', 'article', '=');
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->groupBy('n.nid');
    $query->orderBy('n.created', 'DESC');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $nid = $row->nid;
      $title = $row->title;
      $node_path = '/node/' . $nid;
      $node_alias = $this->pathAliasManager->getAliasByPath($node_path);

      // Page views and unique visitors
      $query_v = $this->database->select('analytics_node_visits', 'anv');
      $query_v->condition('anv.nid', $nid);
      $query_v->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_v->addExpression('SUM(anv.visit_count)', 'total_visit_count');
      $page_views = $query_v->execute()->fetchField();

      if (empty($page_views)) {
        $page_views = 0;
      }

      $query_u = $this->database->select('analytics_node_visits', 'anv');
      $query_u->condition('anv.nid', $nid);
      $query_u->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_u->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
      $page_unique_visitors = $query_u->execute()->fetchField();

      if (empty($page_unique_visitors)) {
        $page_unique_visitors = 0;
      }

      $rows[] = [
        'data' => [
          ['data' => Markup::create('<a href="' . $node_alias . '">' . $title . '</a>'), 'class' => 'title'],
          ['data' => $page_views, 'class' => 'page-views'],
          ['data' => $page_unique_visitors, 'class' => 'unique-visitors'],
        ],
        'id' => 'article-hits-' . $row->nid,
      ];
    }

    $build_container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['featured-column-month-container', 'month-' . $month_data['month_no'] . '-' . $month_data['year']]],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#empty' => $this->t('Sorry. No data available.'),
        '#attributes' => ['class' => ['article-hits-list']],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return [
      'output' => $build_container,
      'context' => $month_data,
    ];
  }

  public function getHeadlinedShowsContent(int $talk_show_nid, string $show_url, $month_data) {
    // Convert alias → internal system path
    $internal_path = $this->pathAliasManager->getPathByAlias($show_url);
    $nid = 0;
    if (preg_match('/^\/node\/(\d+)$/', $internal_path, $matches)) {
      $nid = $matches[1];
    }

    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $header = [
      ['data' => $this->t('Title'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Page Views'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Unique Visitors'), 'attributes' => ['width' => '33.3333%']],
    ];

    // List
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->innerJoin('node_field_data', 'n', 'anv.nid = n.nid');;
    $query->innerJoin('node__field_your_show_link', 'fsn', 'fsn.entity_id = n.nid');
    $query->fields('n', ['nid','title']);
    $query->condition('fsn.field_your_show_link_target_id', $talk_show_nid, '=');
    $query->condition('n.type', 'upcoming_show_topics', '=');
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->groupBy('n.nid');
    $query->orderBy('n.created', 'DESC');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $nid = $row->nid;
      $title = $row->title;
      $node_path = '/node/' . $nid;
      $node_alias = $this->pathAliasManager->getAliasByPath($node_path);

      // Page views and unique visitors
      $query_v = $this->database->select('analytics_node_visits', 'anv');
      $query_v->condition('anv.nid', $nid);
      $query_v->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_v->addExpression('SUM(anv.visit_count)', 'total_visit_count');
      $page_views = $query_v->execute()->fetchField();

      if (empty($page_views)) {
        $page_views = 0;
      }

      $query_u = $this->database->select('analytics_node_visits', 'anv');
      $query_u->condition('anv.nid', $nid);
      $query_u->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_u->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
      $page_unique_visitors = $query_u->execute()->fetchField();

      if (empty($page_unique_visitors)) {
        $page_unique_visitors = 0;
      }

      $rows[] = [
        'data' => [
          ['data' => Markup::create('<a href="' . $node_alias . '">' . $title . '</a>'), 'class' => 'title'],
          ['data' => $page_views, 'class' => 'page-views'],
          ['data' => $page_unique_visitors, 'class' => 'unique-visitors'],
        ],
        'id' => 'upcoming-show-topics-' . $row->nid,
      ];
    }

    $build_container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['headline-show-month-container', 'month-' . $month_data['month_no'] . '-' . $month_data['year']]],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#empty' => $this->t('Sorry. No data available.'),
        '#attributes' => ['class' => ['headline-show-hits-list']],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return [
      'output' => $build_container,
      'context' => $month_data,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFeaturedGuestContent(int $talk_show_nid, string $show_url, $month_data) {
    // Convert alias → internal system path
    $internal_path = $this->pathAliasManager->getPathByAlias($show_url);
    $nid = 0;
    if (preg_match('/^\/node\/(\d+)$/', $internal_path, $matches)) {
      $nid = $matches[1];
    }

    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $header = [
      ['data' => $this->t('Title'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Page Views'), 'attributes' => ['width' => '33.3333%']],
      ['data' => $this->t('Unique Visitors'), 'attributes' => ['width' => '33.3333%']],
    ];

    // List
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->innerjoin('node_field_data', 'n', 'anv.nid = n.nid');
    $query->innerjoin('node__field_highlight_talk_show', 'hts', 'hts.entity_id = n.nid');
    $query->fields('n',['nid','title']);
    $query->condition('hts.field_highlight_talk_show_target_id', $talk_show_nid, '=');
    $query->condition('n.type', 'highlight_upcoming_guest', '=');
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->groupBy('n.nid');
    $query->orderBy('n.created', 'DESC');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $nid = $row->nid;
      $title = $row->title;
      $node_path = '/node/' . $nid;
      $node_alias = $this->pathAliasManager->getAliasByPath($node_path);

      // Page views and unique visitors
      $query_v = $this->database->select('analytics_node_visits', 'anv');
      $query_v->condition('anv.nid', $nid);
      $query_v->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_v->addExpression('SUM(anv.visit_count)', 'total_visit_count');
      $page_views = $query_v->execute()->fetchField();

      if (empty($page_views)) {
        $page_views = 0;
      }

      $query_u = $this->database->select('analytics_node_visits', 'anv');
      $query_u->condition('anv.nid', $nid);
      $query_u->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_u->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
      $page_unique_visitors = $query_u->execute()->fetchField();

      if (empty($page_unique_visitors)) {
        $page_unique_visitors = 0;
      }

      $rows[] = [
        'data' => [
          ['data' => Markup::create('<a href="' . $node_alias . '">' . $title . '</a>'), 'class' => 'title'],
          ['data' => $page_views, 'class' => 'page-views'],
          ['data' => $page_unique_visitors, 'class' => 'unique-visitors'],
        ],
        'id' => 'feature-guest-hits-' . $row->nid,
      ];
    }

    $build_container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['feature-your-guest-month-container', 'month-' . $month_data['month_no'] . '-' . $month_data['year']]],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#empty' => $this->t('Sorry. No data available.'),
        '#attributes' => ['class' => ['feature-your-guest-list']],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return [
      'output' => $build_container,
      'context' => $month_data,
    ];
  }

  public function getProgramArchivesContent(int $talk_show_nid, string $show_url, $month_data) {
    // Convert alias → internal system path
    $internal_path = $this->pathAliasManager->getPathByAlias($show_url);
    $nid = 0;
    if (preg_match('/^\/node\/(\d+)$/', $internal_path, $matches)) {
      $nid = $matches[1];
    }

    $month = $month_data['month_no'];
    $year = $month_data['year'];

    // Build first and last day of the month
    $start = "$year-$month-01";
    $end = date("Y-m-t", strtotime($start));   // last day of month

    $header = [
      ['data' => $this->t('Title'), 'attributes' => ['width' => '20%']],
      ['data' => $this->t('Page Views'), 'attributes' => ['width' => '20%']],
      ['data' => $this->t('Unique Visitors'), 'attributes' => ['width' => '20%']],
      ['data' => $this->t('Audio Plays'), 'attributes' => ['width' => '20%']],
      ['data' => $this->t('Video Plays'), 'attributes' => ['width' => '20%']],
    ];

    // List
    $query = $this->database->select('analytics_node_visits', 'anv');
    $query->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->innerJoin('node_field_data', 'n', 'anv.nid = n.nid');
    $query->innerJoin('node__field_archive_includes', 'fai', 'fai.entity_id = n.nid');
    $query->fields('n', ['nid','title']);
    $query->condition('fai.field_archive_includes_target_id', $talk_show_nid, '=');
    $query->condition('n.type', 'archive_descriptions', '=');
    $query->condition('anv.visit_date', [$start, $end], 'BETWEEN');
    $query->groupBy('n.nid');
    $query->orderBy('n.created', 'DESC');
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $nid = $row->nid;
      $title = $row->title;
      $node_path = '/node/' . $nid;
      $node_alias = $this->pathAliasManager->getAliasByPath($node_path);

      // Page views and unique visitors
      $query_v = $this->database->select('analytics_node_visits', 'anv');
      $query_v->condition('anv.nid', $nid);
      $query_v->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_v->addExpression('SUM(anv.visit_count)', 'total_visit_count');
      $page_views = $query_v->execute()->fetchField();

      if (empty($page_views)) {
        $page_views = 0;
      }

      $query_u = $this->database->select('analytics_node_visits', 'anv');
      $query_u->condition('anv.nid', $nid);
      $query_u->condition('anv.visit_date', [$start, $end], 'BETWEEN');
      $query_u->addExpression('COUNT(DISTINCT anv.ip_address)', 'unique_visitors');
      $page_unique_visitors = $query_u->execute()->fetchField();

      if (empty($page_unique_visitors)) {
        $page_unique_visitors = 0;
      }

      // Media plays, watch and downloads
      $query_a = $this->database->select('analytics_audio_video_visits', 'aavv');
      $query_a->condition('aavv.nid', $nid);
      $query_a->condition('aavv.visit_date', [$start, $end], 'BETWEEN');
      $query_a->addExpression('SUM(aavv.audio_play_count)', 'total_audio_play_count');
      $query_a->addExpression('SUM(aavv.audio_view_count)', 'total_audio_view_count');
      $audio_play_result = $query_a->execute()->fetchObject();

      $audio_plays = 0;
      $audio_plays_display = '';
      if (!empty($audio_play_result)) {
        $total_audio_play_count = $audio_play_result->total_audio_play_count;
        if (empty($total_audio_play_count)) {
          $total_audio_play_count = 0;
        }
        $total_audio_view_count = $audio_play_result->total_audio_view_count;
        if (empty($total_audio_view_count)) {
          $total_audio_view_count = 0;
        }
        $audio_plays = $total_audio_play_count + $total_audio_view_count;
        $audio_plays_display = ' (' . $total_audio_play_count . ' / ' . $total_audio_view_count . ')';
      }
      if (empty($audio_plays)) {
        $audio_plays = 0;
      }

      $query_av = $this->database->select('analytics_audio_video_visits', 'aavv');
      $query_av->condition('aavv.nid', $nid);
      $query_av->condition('aavv.visit_date', [$start, $end], 'BETWEEN');
      $query_av->addExpression('SUM(aavv.video_play_count)', 'total_video_play_count');
      $query_av->addExpression('SUM(aavv.video_view_count)', 'total_video_view_count');
      $video_play_result = $query_av->execute()->fetchObject();

      $video_plays = 0;
      $video_plays_display = '';
      if (!empty($video_play_result)) {
        $total_video_play_count = $audio_play_result->total_audio_play_count;
        if (empty($total_video_play_count)) {
          $total_video_play_count = 0;
        }
        $total_video_view_count = $audio_play_result->total_video_view_count;
        if (empty($total_video_view_count)) {
          $total_video_view_count = 0;
        }
        $video_plays = $total_video_play_count + $total_video_view_count;
        $video_plays_display = ' (' . $total_video_play_count . ' / ' . $total_video_view_count . ')';
      }
      if (empty($video_plays)) {
        $video_plays = 0;
      }

      $query_m = $this->database->select('analytics_audio_video_visits', 'aavv');
      $query_m->condition('aavv.nid', $nid);
      $query_m->condition('aavv.visit_date', [$start, $end], 'BETWEEN');
      $query_m->addExpression('SUM(aavv.media_download_count)', 'total_media_download_count');
      $downloads = $query_m->execute()->fetchField();

      if (empty($downloads)) {
        $downloads = 0;
      }

      $rows[] = [
        'data' => [
          ['data' => Markup::create('<a href="' . $node_alias . '">' . $title . '</a>'), 'class' => 'title'],
          ['data' => $page_views, 'class' => 'page-views'],
          ['data' => $page_unique_visitors, 'class' => 'unique-visitors'],
          ['data' => $audio_plays . $audio_plays_display, 'class' => 'play-count'],
          ['data' => $video_plays . $video_plays_display, 'class' => 'play-count'],
        ],
        'id' => 'archive-description-' . $row->nid,
      ];
    }

    $build_container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['archives-month-container', 'month-' . $month_data['month_no'] . '-' . $month_data['year']]],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#empty' => $this->t('Sorry. No data available.'),
        '#attributes' => ['class' => ['archives-list']],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return [
      'output' => $build_container,
      'context' => $month_data,
    ];
  }

  public function getPagerElement($element){
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['analytics-pager-' . $element]],
      '#use-ajax' => TRUE,
      'pager' => [
        '#type' => 'pager',
        '#element' => $element,
      ],
    ];
  }

  public function getMonthsInfo() {
    $data = [];

    $months = [
      'month_0' => 'now',
      'month_1' => '-1 month',
      'month_2' => '-2 months',
    ];

    foreach ($months as $key => $offset) {
      $dt = new \DateTime('first day of ' . $offset);
      $data[$key] = [
        'month_key'   => $key,
        'month_no'   => $dt->format('m'),
        'month_name' => $dt->format('F'),
        'year'       => $dt->format('Y'),
        'month_year' => $dt->format('F') . ', ' . $dt->format('Y'),
      ];
    }

    return $data;
  }
}
