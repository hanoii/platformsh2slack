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

   private $slack;

  /**
   * Instantiate a new Webhook adapter
   *
   * @param string $slack_endpoint
   * @param string $slack_channel
   * @param string $region PLatform.sh region (us, eu)
   * @param array $settings
   * @return void
   */
  function __construct($slack_endpoint, $slack_channel, $region, array $settings = []) {
    // Default settings
    $this->config = $settings + [
      'region' => $region,
      'commit_limit' => 10,
      'routes' => false,
      'configurations' => false,
      'debug' => false,
      'attachment_color' => null
    ];

    $this->request = Request::createFromGlobals();

    // Default settings
    $slack_settings = [
      'username' => 'Platform.sh',
      'channel' => $slack_channel,
      'icon' => 'https://raw.githubusercontent.com/hanoii/platformsh2slack/master/platformsh.png',
    ];

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

  function validateToken($token) {
    if ($token != $this->request->query->get('token')) {
      $response = new Response('Invalid token', 403);
      $response->send();

      throw new \RuntimeException('Invalid token');
    }
  }

  function processPlatformshPayload() {
    $show_routes = $this->config['routes'];
    $show_configurations = $this->config['configurations'];

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

    // Handle webhook
    switch ($platformsh->type) {
      case 'environment.push':
        $text = "$name pushed $commits_count_str to branch `$branch` of <$project_url|$project>";
        if ($branch == 'master') {
          $show_configurations = true;
        }
        break;

      case 'environment.branch':
        $text = "$name created a branch `$branch` of <$project_url|$project>";
        $show_routes = true;
        break;

      case 'environment.delete':
        $text = "$name deleted the branch `$branch` of <$project_url|$project>";
        break;

      case 'environment.merge':
        $text = "$name merged branch `{$platformsh->parameters->from}` into `{$platformsh->parameters->into}` of <$project_url|$project>";
        if ($platformsh->parameters->into == 'master') {
          $show_configurations = true;
        }
        break;

      case 'environment.subscription.update':
        $text = "$name updated the subscription of <$project_url|$project>";
        $show_configurations = true;
        break;

      case 'project.domain.create':
      case 'project.domain.update':
        $text = "$name updated domain `{$platformsh->payload->domain->name}` of <$project_url|$project>";
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
        $text = "$name created the snapshot `{$platformsh->payload->backup_name}` from `$branch` of <$project_url|$project>";
        break;

      case 'environment.deactivate':
        $text = "$name deactivated the environment `$branch` of <$project_url|$project>";
        break;

      default:
        $text = "$name triggerred an unhandled webhook `{$platformsh->type}` to branch `$branch` of <$project_url|$project>";
        if ($this->config['debug']) {
          $filename = $this->config['debug'] . '/platformsh2slack.' . $platformsh->type . '.' . time() . '.json';
          file_put_contents($filename, $json);
          $this->slack->attach(array(
            'text' => 'JSON saved to ' . $filename,
            'fallback' => 'JSON saved to ' . $filename,
            'color' => $this->config['attachment_color'],
          ));
        }
        break;
    }

    // Result
    $this->slack->attach(array(
      'text' => ucfirst($platformsh->result),
      'fallback' => ucfirst($platformsh->result),
      'color' => $platformsh->result == 'success' ? 'good' : 'danger',
    ));

    // Environment configuration
    if ($show_configurations && preg_match('/Environment configuration:(.*)Environment routes/s', $platformsh->log, $matches)) {
      $environment_configuration = $this->trim($matches[1]);
      $this->slack->attach(array(
        'title' => 'Environment configuration',
        'text' => $environment_configuration,
        'fallback' => $environment_configuration,
        'color' => $this->config['attachment_color'],
      ));
    }

    // Environment routes
    if ($show_routes && preg_match('/Environment routes:(.*)/s', $platformsh->log, $matches)) {
      $routes = $this->trim($matches[1]);
      $this->slack->attach(array(
        'title' => 'Environment routes',
        'text' => $routes,
        'fallback' => $routes,
        'color' => $this->config['attachment_color'],
      ));
    }
  }

  function send() {

    $this->processPlatformshPayload();

    $this->slack->send();

    // Make sure this request is never cached
    $response = new Response();
    $response->headers->addCacheControlDirective('no-cache');
    $response->headers->addCacheControlDirective('must-revalidate');
    $response->headers->addCacheControlDirective('proxy-revalidate');
    $response->headers->addCacheControlDirective('max-age', 0);
    $response->send();
  }

  /**
  * Sample method
  *
  * Always create a corresponding docblock for each method, describing what it is for,
  * this helps the phpdocumentator to properly generator the documentation
  *
  * @param string $param1 A string containing the parameter, do this for each parameter to the function, make sure to make it descriptive
  *
  * @return string
  */
   public function method1($param1){
      return "Hello World";
   }
}
