<?php
/**
 * @file
 * Contains GoogleTagResponseSubscriber.
 */
namespace Drupal\google_tag\EventSubscriber;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
/**
 * Class GoogleTagResponseSubscriber
 * @package Drupal\google_tag\EventSubscriber
 */
class GoogleTagResponseSubscriber implements EventSubscriberInterface {
  /**
   * The config object for the google_tag settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;
  /**
   * An alias manager to find the alias for the current system path.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;
  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;
  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;
  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;
  /**
   * Constructs a new Google Tag response subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The alias manager service.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher service.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(ConfigFactoryInterface $configFactory, AliasManagerInterface $alias_manager, PathMatcherInterface $path_matcher, CurrentPathStack $current_path, AccountProxyInterface $current_user) {
    $this->config = $configFactory->get('google_tag.settings');
    $this->aliasManager = $alias_manager;
    $this->pathMatcher = $path_matcher;
    $this->currentPath = $current_path;
    $this->currentUser = $current_user;
  }
  /**
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   */
  public function addTag(FilterResponseEvent $event) {
    if (!$event->isMasterRequest()) {
      return;
    }
    $request = $event->getRequest();
    $response = $event->getResponse();
    if ($this->tagApplies($request, $response)) {
      $container_id = $this->getContainerId();
      $compact = $this->getCompact();
      // Insert snippet after the opening body tag.
      $pattern = [
        '@</head>@',
        '@<body[^>]*>@'
      ];
      $replace = [
        $this->getHeadTag($container_id, $compact) . '$0',
        '$0' . $this->getTag($container_id, $compact)
      ];
      $response_text = preg_replace($pattern, $replace, $response->getContent(), 1);
      $response->setContent($response_text);
    }
  }
  /**
   * Fetch the container id, here to make it easier to overload.
   *
   * @return string
   */
  public function getContainerId() {
    $container_id = $this->config->get('container_id');
    return trim(json_encode($container_id), '"');
  }

  public function getCompact() {
    return $this->config->get('compact_tag');
  }

  /**
   * Return the text for the head tag.
   *
   * @param string $container_id
   *   The Google Tag Manager container ID.
   * @param bool $compact
   *   Whether or not the tag should be compacted (whitespace removed).
   *
   * @return string
   *   The full text of the Google Tag manager script/embed.
   */
  public function getHeadTag($container_id, $compact = FALSE) {
    // Build script tags.
    $script = <<<EOS
<script type="text/javascript">
(function(w,d,s,l,i){
  w[l]=w[l]||[];
  w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
  var f=d.getElementsByTagName(s)[0];
  var j=d.createElement(s);
  var dl=l!='dataLayer'?'&l='+l:'';
  j.src='//www.googletagmanager.com/gtm.js?id='+i+dl;
  j.type='text/javascript';
  j.async=true;
  f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','$container_id');
</script>
EOS;
    return $this->prepareTag($script, $compact);
  }
  /**
   * Return the text for the body tag.
   *
   * @param string $container_id
   *   The Google Tag Manager container ID.
   * @param bool $compact
   *   Whether or not the tag should be compacted (whitespace removed).
   *
   * @return string
   *   The full text of the Google Tag manager script/embed.
   */
  public function getTag($container_id, $compact = FALSE) {
    // Build script tags.
    $noscript = <<<EOS
<noscript><iframe src="//www.googletagmanager.com/ns.html?id=$container_id"
 height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
EOS;
    return $this->prepareTag($noscript, $compact);
  }
  /**
   * @param string $script
   * @param bool $compact
   * @return string
   */
  private function prepareTag($script, $compact) {
    $script = ($compact) ? $this->compressHtml($script) : $script;
    return $this->addComments($script);
  }
  /**
   * Return the HTML compressed.
   *
   * @param string $data
   * @return mixed
   */
  private function compressHtml($data) {
    return str_replace(["\n", '  '], '', $data);
  }

  /**
   * Return the comments around the script.
   *
   * @param string $script
   * @return string
   */
  private function addComments($script) {
    return <<<EOS
<!-- Google Tag Manager -->
$script
<!-- End Google Tag Manager -->
EOS;
  }

  /**
   * Determines whether or not the tag should be included on a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, used for path matching.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response, used for matching status codes.
   *
   * @return bool
   *   Whether or not the tag should be included on the page.
   */
  protected function tagApplies(Request $request, Response $response) {
    $id = $this->config->get('container_id');
    if (empty($id)) {
      // No container ID.
      return FALSE;
    }
    if (!$this->statusCheck($response) && !$this->pathCheck($request)) {
      // Omit snippet based on the response status and path conditions.
      return FALSE;
    }
    if (!$this->roleCheck()) {
      // Omit snippet based on the response status and path conditions.
      return FALSE;
    }
    if (!($response instanceof HtmlResponse)) {
      // Omit snippet because the response is not HTML.
      return FALSE;
    }
    return TRUE;
  }
  /**
   * HTTP status code check. This checks to see if status check is even used
   * before checking the status.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response object.
   *
   * @return bool
   *   True if the check is enabled and the status code matches the list of
   *   enabled statuses.
   */
  protected function statusCheck(Response $response) {
    static $satisfied;
    if (!isset($satisfied)) {
      $toggle = $this->config->get('status_toggle');
      $statuses = $this->config->get('status_list');
      if (!$toggle) {
        return FALSE;
      }
      else {
        // Get the HTTP response status.
        $status = $response->getStatusCode();
        $satisfied = strpos($statuses, (string) $status) !== FALSE;
      }
    }
    return $satisfied;
  }
  /**
   * Determines whether or not the tag should be included on a page based on
   * the path settings.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   True if the tag should be included for the current request based on path
   *   settings.
   */
  protected function pathCheck(Request $request) {
    static $satisfied;
    if (!isset($satisfied)) {
      $toggle = $this->config->get('path_toggle');
      $pages = Unicode::strtolower($this->config->get('path_list'));
      if (empty($pages)) {
        return ($toggle == GOOGLE_TAG_DEFAULT_INCLUDE) ? TRUE : FALSE;
      }
      else {
        // Compare the lowercase path alias (if any) and internal path.
        $path = rtrim($this->currentPath->getPath($request), '/');
        $path_alias = Unicode::strtolower($this->aliasManager->getAliasByPath($path));
        $satisfied = $this->pathMatcher->matchPath($path_alias, $pages) || (($path != $path_alias) && $this->pathMatcher->matchPath($path, $pages));
        $satisfied = ($toggle == GOOGLE_TAG_DEFAULT_INCLUDE) ? !$satisfied : $satisfied;
      }
    }
    return $satisfied;
  }
  /**
   * Determines whether or not the tag should be included on a page based on
   * user roles.
   *
   * @return bool
   *   True is the check is enabled and the user roles match the settings.
   */
  protected function roleCheck() {
    static $satisfied;
    if (!isset($satisfied)) {
      $toggle = $this->config->get('role_toggle');
      $roles = $this->config->get('role_list');
      if (empty($roles)) {
        return ($toggle == GOOGLE_TAG_DEFAULT_INCLUDE) ? TRUE : FALSE;
      }
      else {
        $satisfied = FALSE;
        // Check user roles against listed roles.
        $satisfied = (bool) array_intersect($roles, $this->currentUser->getRoles());
        $satisfied = ($toggle == GOOGLE_TAG_DEFAULT_INCLUDE) ? !$satisfied : $satisfied;
      }
    }
    return $satisfied;
  }
  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = array('addTag', -500);
    return $events;
  }
}
