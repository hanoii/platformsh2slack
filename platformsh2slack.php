<?php
/**
 * @file
 * Platform.sh -> Slack adapter.
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$config_file = 'platformsh2slack.yaml';

// Token simple auth and require config file
if (!file_exists($config_file)
  || empty($_GET['token'])
  || !($config = Yaml::parse(file_get_contents($config_file)))
  || $_GET['token'] != $config['token']) {
  print 'No config or valid token.';
  die();
}

/**
 * Small trim utilizty for bits of the platfrom.sh log output
 */
function platformsh2slack_trim_log($str) {
  return trim(preg_replace('/[\n]+[ ]*/s', "\n", $str), "\n ");
}

// Defaults can be overriden on the yaml file
$defaults = array();

// Color use for informational attachments
$defaults['slack']['colors']['attachment'] = '#e8e8e8';

$config = array_replace_recursive($defaults, $config);

$show_routes = $config['routes'];
$show_configurations = $config['configurations'];

$json = file_get_contents('php://input');
$platformsh = json_decode($json);

if (!empty($platformsh)) {
  // Default settings
  $settings = [
    'username' => 'Platform.sh',
    'channel' => $config['slack']['channel'],
    'icon' => 'https://pbs.twimg.com/profile_images/515156001591283712/UCMw85fT.png',
  ];

  // Instantiate slack client
  $client = new Maknz\Slack\Client(
    $config['slack']['url'],
    $settings
  );

  // Explicitely set slack message
  $message = $client->createMessage();

  // Authora name
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
  $host = $config['platformsh']['host'];
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
      if ($c == $config['commit_limit']) {
        $commits[] = "... and more, only $c were shown.";
        break;
      }
    }
    $commits = implode("\n", $commits);
    $message->attach(array(
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
        $message->attach(array(
          'title' => 'SSL',
          'text' => "*CA: * {$platformsh->payload->domain->ssl->ca}\n*Expires: * {$platformsh->payload->domain->ssl->expires_on}",
          'fallback' => "CA: {$platformsh->payload->domain->ssl->ca}\n",
          'color' => $config['slack']['colors']['attachment'],
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
      if ($config['debug']) {
        $filename = '/tmp/webhook.' . $platformsh->type . '.' . time() . '.json';
        file_put_contents($filename, $json);
        $message->attach(array(
          'text' => 'JSON saved to ' . $filename,
          'fallback' => 'JSON saved to ' . $filename,
          'color' => $config['slack']['colors']['attachment'],
        ));
      }
      break;
  }

  // Result
  $message->attach(array(
    'text' => ucfirst($platformsh->result),
    'fallback' => ucfirst($platformsh->result),
    'color' => $platformsh->result == 'success' ? 'good' : 'danger',
  ));

  // Environment configuration
  if ($show_configurations && preg_match('/Environment configuration:(.*)Environment routes/s', $platformsh->log, $matches)) {
    $environment_configuration = platformsh2slack_trim_log($matches[1]);
    $message->attach(array(
      'title' => 'Environment configuration',
      'text' => $environment_configuration,
      'fallback' => $environment_configuration,
      'color' => $config['slack']['colors']['attachment'],
    ));
  }

  // Environment routes
  if ($show_routes && preg_match('/Environment routes:(.*)/s', $platformsh->log, $matches)) {
    $routes = platformsh2slack_trim_log($matches[1]);
    $message->attach(array(
      'title' => 'Environment routes',
      'text' => $routes,
      'fallback' => $routes,
      'color' => $config['slack']['colors']['attachment'],
    ));
  }

  $message->send($text);
}
