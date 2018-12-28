<?php

namespace Hanoii\Platformsh2Slack;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
*  A sample class
*
*  Use this section to define what this class is doing, the PHPDocumentator will use this
*  to automatically generate an API documentation using this information.
*
*  @author yourname
*/
class Platformsh2Slack {

  /**  @var array $settings Instance settings */
  private $config = [];

  private $request;

  private $slack_text = '';

  private $slack;

  /**
   * Instantiate a new Webhook adapter
   *
   * @param string $slack_endpoint
   * @param array $settings
   * @return void
   */
  function __construct($slack_endpoint, array $settings = []) {
    // Default settings
    $this->config = $settings + [
      'channel' => null,
      'region' => 'eu',
      'commit_limit' => 10,
      'routes' => false,
      'redirects' => false,
      'basic_auth' => false,
      'configurations' => false,
      'attachment_color' => '#e8e8e8',
      'debug' => null,
      'debug_all' => false,
      'project' => null,
    ];

    $this->request = Request::createFromGlobals();

    // Default settings
    $slack_settings = [
      'username' => 'Platform.sh',
      'icon' => 'https://raw.githubusercontent.com/hanoii/platformsh2slack/master/platformsh.png',
    ];

    if ($this->config['channel']) {
      $slack_settings['channel'] = $this->config['channel'];
    }

    // Instantiate slack client
    $client = new \Maknz\Slack\Client(
      $slack_endpoint,
      $slack_settings
    );

    // Explicitely set slack message
    $this->slack = $client->createMessage();
  }

  function trim($str) {
    return trim(preg_replace('/[\n]+[ ]*/s', "\n", $str), "\n ");
  }

  /**
  * Validate that a token is present in the request
  *
  * @param string $token Token to validate
  *
  * @return void
  */
  function validateToken($token) {
    if ($token != $this->request->query->get('token')) {
      $response = new Response('Invalid token', 403);
      $response->send();

      throw new \RuntimeException('Invalid token');
    }
  }

  /**
  * Parse Platform.sh payload into Slack formatted message
  */
  function processPlatformshPayload() {
    $show_routes = $this->config['routes'];
    $show_redirects = $this->config['redirects'];
    $show_configurations = $this->config['configurations'];
    $show_basic_auth = $this->config['basic_auth'];

    $json = $this->request->getContent();
    $platformsh = json_decode($json);

    if (empty($platformsh)) {
      throw new \RuntimeException('Invalid Platform.sh webhook payload');
    }

    // Author name
    $name = $platformsh->payload->user->display_name;

    // Branch
    $branch = 'not-found-on-payload';
    if (!empty($platformsh->parameters->environment)) {
      $branch = $platformsh->parameters->environment;
    }
    else if (!empty($platformsh->payload->environment->name)) {
      $branch = $platformsh->payload->environment->name;
    }

    // Project
    $project = $platformsh->project;

    // Region/project url
    $host = $this->config['region'] . '.platform.sh';
    $project_url = "https://$host/projects/$project/environments/$branch";

    // Optional project name
    if ($this->config['project']) {
      $project = $this->config['project'];
    }

    // Commits
    if (!empty($platformsh->payload->commits_count)) {
      $commits_count = $platformsh->payload->commits_count;
      $commits_count_str = "$commits_count commit" . ($commits_count > 1 ? 's' : '');

      $c = 0;
      foreach ($platformsh->payload->commits as $commit) {
        $sha = substr($commit->sha, 0, 8);
        $commits[] = "$sha: {$commit->message} - {$commit->author->name}";
        $c++;
        if ($c == $this->config['commit_limit']) {
          $commits[] = "... and more, only $c were shown.";
          break;
        }
      }
      $commits = implode("\n", $commits);
      $this->slack->attach(array(
        'text' => $commits,
        'fallback' => $commits,
        'color' => '#345',
      ));
    }

    $debug = false;

    // Handle webhook
    switch ($platformsh->type) {

      case 'environment.activate':
        $this->slack_text = "$name activated environment for branch `$branch` of <$project_url|$project>";
        $show_configurations = true;
        $show_routes = true;
        $show_redirects = true;
        $show_basic_auth = true;
        break;

      case 'environment.update.http_access':
        $this->slack_text = "$name changed HTTP Authentication settings on environment `$branch` of <$project_url|$project>";
        $show_routes = true;
        $show_basic_auth = true;
        break;

      case 'environment.access.add':
        $this->slack_text = "$name added {$platformsh->payload->access->display_name} to `$branch` of <$project_url|$project>";
        break;

      case 'environment.access.remove':
        $this->slack_text = "$name removed {$platformsh->payload->access->display_name} from `$branch` of <$project_url|$project>";
        break;

      case 'environment.redeploy':
        $this->slack_text = "$name redeployed environment `$branch` of <$project_url|$project>";
        break;

      case 'environment.synchronize':
        $parent = $platformsh->parameters->from;
        $child = $platformsh->parameters->into;
        if ($platformsh->parameters->synchronize_code) {
          $payload[] = '*code*';
        }
        if ($platformsh->parameters->synchronize_data) {
          $payload[] = '*data & files*';
        }

        $payload = implode(', ', $payload);

        $this->slack_text = "$name synchronized $payload from `$parent` into `$child` environment of <$project_url|$project>";
        break;

      case 'environment.push':
        $this->slack_text = "$name pushed $commits_count_str to branch `$branch` of <$project_url|$project>";
        if ($branch == 'master') {
          $show_configurations = true;
        }
        break;

      case 'environment.branch':
        $this->slack_text = "$name created a branch `$branch` of <$project_url|$project>";
        $show_routes = true;
        $show_redirects = true;
        $show_basic_auth = true;
        break;

      case 'environment.delete':
        $this->slack_text = "$name deleted the branch `$branch` of <$project_url|$project>";
        break;

      case 'environment.merge':
        $this->slack_text = "$name merged branch `{$platformsh->parameters->from}` into `{$platformsh->parameters->into}` of <$project_url|$project>";
        if ($platformsh->parameters->into == 'master') {
          $show_configurations = true;
        }
        break;

      case 'environment.subscription.update':
        $this->slack_text = "$name updated the subscription of <$project_url|$project>";
        $show_configurations = true;
        break;

      case 'project.domain.create':
      case 'project.domain.update':
        $this->slack_text = "$name updated domain `{$platformsh->payload->domain->name}` of <$project_url|$project>";
        if (!empty($platformsh->payload->domain->ssl->has_certificate)) {
          $this->slack->attach(array(
            'title' => 'SSL',
            'text' => "*CA: * {$platformsh->payload->domain->ssl->ca}\n*Expires: * {$platformsh->payload->domain->ssl->expires_on}",
            'fallback' => "CA: {$platformsh->payload->domain->ssl->ca}\n",
            'color' => $this->config['attachment_color'],
            'mrkdwn_in' => array('text'),
          ));
        }
        break;

      case 'environment.backup':
        $this->slack_text = "$name created the snapshot `{$platformsh->payload->backup_name}` from `$branch` of <$project_url|$project>";
        break;

      case 'environment.deactivate':
        $this->slack_text = "$name deactivated the environment `$branch` of <$project_url|$project>";
        break;

      case 'environment.variable.create':
        $this->slack_text = "$name created variable `{$platformsh->payload->variable->name}` on <$project_url|$project>";
        break;

      case 'environment.variable.update':
        $this->slack_text = "$name updated variable `{$platformsh->payload->variable->name}` on <$project_url|$project>";
        break;

      case 'environment.variable.delete':
        $this->slack_text = "$name deleted variable `{$platformsh->payload->variable->name}` on <$project_url|$project>";
        break;

      default:
        $this->slack_text = "$name triggerred an unhandled webhook `{$platformsh->type}` to branch `$branch` of <$project_url|$project>";
        $this->slack->attach(array(
          'title' => 'Description',
          'color' => $this->config['attachment_color'],
          'text' => strip_tags($platformsh->description),
          'fallback' => strip_tags($platformsh->description),
        ));
        if ($this->config['debug']) {
          $debug = true;
        }
        break;
    }

    if ($debug || ($this->config['debug'] && $this->config['debug_all'])) {
      $filename = $this->config['debug'] . '/platformsh2slack.' . $platformsh->type . '.' . time() . '.json';
      file_put_contents($filename, $json);
      $this->slack->attach(array(
        'text' => 'JSON saved to ' . $filename,
        'fallback' => 'JSON saved to ' . $filename,
        'color' => $this->config['attachment_color'],
      ));
    }

    // Result
    $this->slack->attach(array(
      'text' => ucfirst($platformsh->result),
      'fallback' => ucfirst($platformsh->result),
      'color' => $platformsh->result == 'success' ? 'good' : 'danger',
    ));

    // Environment configuration
    if ($show_configurations && preg_match('/Environment configuration:?(.*)Environment routes/s', $platformsh->log, $matches)) {
      $environment_configuration = $this->trim($matches[1]);
      $this->slack->attach(array(
        'title' => 'Environment configuration',
        'text' => $environment_configuration,
        'fallback' => $environment_configuration,
        'color' => $this->config['attachment_color'],
      ));
    }

    // Environment routes
    if ($show_routes && !empty($platformsh->payload->deployment->routes)) {
      $routes_fields = $redirects = [];
      $routes_fallback = $redirects_fallback = '';
      foreach ($platformsh->payload->deployment->routes as $destination => $route) {
        // Only show upstream routes, not redirects.
        if ($route->type == 'upstream') {
          $name = "URL for `{$route->upstream}`";
          $routes_fields[] = [
            'title' => $name,
            'value' => $destination,
          ];
          $routes_fallback .= "- {$name}: {$destination}\n";
        }
        if ($show_redirects && $route->type == 'redirect') {
          $redirects[] = $destination;
        }
      }
      if (!empty($redirects)) {
        $once = false;
        foreach ($redirects as $destination) {
          $redirect = [];
          if (!$once) {
            $redirect['title'] = 'Redirects';
            $once = true;
          }
          $redirect['value'] = "{$destination}";
          $routes_fields[] = $redirect;
        }
      }
      if (!empty($routes_fields)) {
        $this->slack->attach(array(
          'title' => count($routes_fields) > 1 ? 'Environment routes' : '',
          'text' => '',
          'fallback' => $routes_fallback,
          'fields' => $routes_fields,
          'color' => $this->config['attachment_color'],
        ));
      }
    }

    // Basic authentication
    if ($show_basic_auth && !empty($platformsh->payload->environment->http_access->is_enabled)) {
      if (!empty($platformsh->payload->environment->http_access->basic_auth)) {

        $basic_auth_fallback = '';
        $basic_auth_fields = [];
        $once = false;
        foreach ($platformsh->payload->environment->http_access->basic_auth as $basic_auth_user => $basic_auth_pass) {
          $user = $pass = [];
          if (!$once) {
            $user['title'] = 'Username';
            $pass['title'] = 'Password';
            $basic_auth_fallback .= "- *Username* / *Password*\n";
            $once = true;
          }
          $user['value'] = $basic_auth_user;
          $user['short'] = true;
          $pass['value'] = $basic_auth_pass;
          $pass['short'] = true;
          $basic_auth_fields[] = $user;
          $basic_auth_fields[] = $pass;
          $basic_auth_fallback .= "- `$basic_auth_user` / `$basic_auth_pass`\n";
        }
        if (!empty($basic_auth_fields)) {
          $this->slack->attach(array(
            'title' => 'HTTP Authentication',
            'text' => '',
            'fallback' => $basic_auth_fallback,
            'fields' => $basic_auth_fields,
            'color' => $this->config['attachment_color'],
          ));
        }
      }

      if ($platformsh->result != 'success') {
        $this->slack->attach(array(
          'title' => 'Build log',
          'text' => $platformsh->log,
          'fallback' => $platformsh->log,
          'color' => 'danger',
        ));
      }
    }
  }

  /**
  * Send formatted message to slack, making sure the response is not cached.
  */
  function send() {

    $this->processPlatformshPayload();

    $this->slack->send($this->slack_text);

    // Make sure this request is never cached
    $response = new Response();
    $response->headers->addCacheControlDirective('no-cache');
    $response->headers->addCacheControlDirective('must-revalidate');
    $response->headers->addCacheControlDirective('proxy-revalidate');
    $response->headers->addCacheControlDirective('max-age', 0);
    $response->send();
  }
}
