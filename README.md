# Platform.sh -> Slack incoming webhook adapter

This is a simple php script that translates [Platform.sh](https://platform.sh) webhook into a [Slack](http://slack.com/)
formatted message.

You can install this in your php app container and host it there for your project's specific webhooks.

Sponsored by [Infomagnet - builds websites to any design using Drupal](https://infomagnet.com).

![slack-example](https://cloud.githubusercontent.com/assets/677879/19004393/2aae68b4-872c-11e6-9ec4-52bbde84d849.png)

## Installation

You can install the package using the [Composer](https://getcomposer.org/) package manager. You can install it by running this command in your project root:

```sh
composer require hanoii/platformsh2slack
```

Then [create an incoming webhook](https://my.slack.com/services/new/incoming-webhook) on your Slack account for the package to use. You'll need the webhook URL to instantiate the adapter.

## Basic Usage

```php
<?php

// Optional settings
$settings = [
  'channel' => '#random',
  'region' => 'eu',
];

$platformsh2slack = new Hanoii\Platformsh2Slack\Platformsh2Slack(
  'https://hooks.slack.com/...',
  $settings
);

// Optionally protect the request with a token that has to be present in the Platform.sh webhook
$platformsh2slack->validateToken('1234');

// Send the information to slack
$platformsh2slack->send();
```

## Platform.sh build hook

If your application (`.platform.app.yaml`) is already being built with composer:

```yaml
build:
    flavor: composer
```

You can simply add:

```json
    "hanoii/platformsh2slack": "^1.0"
```

To your `composer.json` file of the project and create a small script as per above.

If not, you will have to add a script to the repository and run composer install on your build hook manually.

## Settings

Option | Type | Default | Description
----- | ---- | ------- | -----------
`channel` | string | `null` | The default channel that messages will be sent to, otherwise defaults to what's set on the Slack's incoming webhook
`region` | string | `'eu'` | Platform.sh region where the project is hosted. This is used to build the links to the project. 
`commit_limit` | int | `10` | The number of commits from the payload to include in the Slack message 
`routes` | bool | `false` | Whether to show project's routes on every slack message. If false, it will be shown only when you branch.
`redirects` | bool | `false` | Whether to include project's redirects with routes on every Slack message. If false, redirects will be shown only when you branch.
`basic_auth` | bool | `false` | Whether to show project environment's HTTP Authentication username and password in Slack message.  WARNING: If true, potentially sensitive data passwords will be sent in the clear to your Slack channel.
`configurations` | bool | `false` | Whether to show project's configurations on every slack message. If false, it will be shown only for master when you push, merge or have a subscription plan update.
`attachment_color` | string | `'#e8e8e8'` | RGB color for Slack attachment.
`project` | string | `null` | If present, it will be used as the project name instead of the ID. Project name is misisng in Platform.sh's payload.
`debug` | string | `null` | An optional path where posssible unhandled webhooks JSON can be saved. This is useful if you want to send over the json for me to add support for it.

## Token

This is an optional feature you can choose to use on the script. It's a nice simple validation so that you script is not abused.

If you added:

```php
$platformsh2slack->validateToken('1234');
```

to your script, you will have to append the token the Platform.sh's webhook integration URL.

## Add the integration on platform

Run the following:

```bash
platform integration:add --type=webhook --url="https://www.example.com/platformsh2slack.php?token=TOKEN"
```

## Environoments

You can have this script on any environment, even master. As far as my trials went, even pushing to master works.
