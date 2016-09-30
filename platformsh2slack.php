<?php

$config_file = 'platformsh2slack-config.php';
// Prevent flooding and require config file
if (!file_exists($config_file) || empty($_GET['token']) || !(include $config_file) || !defined('PLATOFRMSH2SLACK_TOKEN') || $_GET['token'] != PLATOFRMSH2SLACK_TOKEN) {
  print 'No config or valid token.';
  die();
}

require __DIR__ . '/vendor/autoload.php';

function platformsh_trim_log($str) {
  return trim(preg_replace('/[\n]+[ ]*/s', "\n", $str), "\n ");
}

$json = file_get_contents('php://input');
$platformsh = json_decode($json);

if (!empty($platformsh)) {

  // Instantiate with defaults, so all messages created
  // will be sent from 'Cyril' and to the #accounting channel
  // by default. Any names like @regan or #channel will also be linked.
  $settings = [
    'username' => 'Platform.sh',
    'channel' => PLATOFRMSH2SLACK_SLACK_CHANNEL,
    'icon' => 'https://pbs.twimg.com/profile_images/515156001591283712/UCMw85fT.png',
  ];

  // Instantiate without defaults
  $client = new Maknz\Slack\Client(
    PLATOFRMSH2SLACK_SLACK_URL,
    $settings
  );

  $message = $client->createMessage();

  $name = $platformsh->payload->user->display_name;
  $branch = 'not-found-on-payload';
  if (!empty($platformsh->parameters->environment)) {
    $branch = $platformsh->parameters->environment;
  }
  else if (!empty($platformsh->payload->environment->name)) {
    $branch = $platformsh->payload->environment->name;
  }
  $project = $platformsh->project;

  // Region/project url
  $host = PLATOFRMSH2SLACK_HOST;
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
      if ($c == PLATOFRMSH2SLACK_COMMIT_LIMIT) {
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

  switch ($platformsh->type) {
    case 'environment.push':
      $text = "$name pushed $commits_count_str to branch `$branch` of <$project_url|$project>";
      break;

    case 'environment.branch':
      $text = "$name created a branch `$branch` of <$project_url|$project>";
      break;

    case 'environment.delete':
      $text = "$name deleted the branch `$branch` of <$project_url|$project>";
      break;

    case 'environment.merge':
      $text = "$name merged branch `{$platformsh->parameters->from}` into `{$platformsh->parameters->into}` of <$project_url|$project>";
      break;

    default:
      $text = "$name triggerred an unhandled webhook `{$platformsh->type}` to branch `$branch` of <$project_url|$project>";
      if (PLATOFRMSH2SLACK_DEBUG) {
        $filename = '/tmp/webhook.' . $platformsh->type . '.' . time() . '.json';
        file_put_contents($filename, $json);
        $message->attach(array(
          'text' => 'JSON saved to ' . $filename,
          'fallback' => 'JSON saved to ' . $filename,
          'color' => '#e8e8e8',
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
  if (preg_match('/Environment configuration:(.*)Environment routes/s', $platformsh->log, $matches)) {
    $environment_configuration = platformsh_trim_log($matches[1]);
    $message->attach(array(
      'title' => 'Environment configuration',
      'text' => $environment_configuration,
      'fallback' => $environment_configuration,
      'color' => '#e8e8e8',
    ));
  }

  // Environment routes
  if (preg_match('/Environment routes:(.*)/s', $platformsh->log, $matches)) {
    $routes = platformsh_trim_log($matches[1]);
    $message->attach(array(
      'title' => 'Environment routes',
      'text' => $routes,
      'fallback' => $routes,
      'color' => '#e8e8e8',
    ));
  }

  $message->send($text);

}
