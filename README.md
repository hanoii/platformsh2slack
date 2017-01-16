# Platform.sh -> Slack incoming webhook adapter

This is a simple php script that translates [Platform.sh](https://platform.sh) webhook into a [Slack](http://slack.com/)
formatted message.

You can install this in your php app container and host it there for your project's specific webhooks.

Sponsored by [Infomagnet - builds websites to any design using Drupal](https://infomagnet.com).

![slack-example](https://cloud.githubusercontent.com/assets/677879/19004393/2aae68b4-872c-11e6-9ec4-52bbde84d849.png)

## Build hook

You need to add something like the following to your app `.platform.app.yaml`:

```yaml
# The hooks executed at various points in the lifecycle of the application.
hooks:
    # We run deploy hook after your application has been deployed and started.
    build: |
      (
        set -e
        git clone --branch v0.4.5 https://github.com/hanoii/platformsh2slack.git public/platformsh2slack
        composer install -d public/platformsh2slack
      )
```

Please look at the [latest tagged release](../../releases) and change the `--branch v0.3.1` above with it if it's not the latest.

## Config file

This scripts look for a `platformsh2slack.yaml` alongside the `platformsh2slack.php` script, you can either:

- create it on another build hook
- or add it to your repository and then add a line to the build hook to move it to where you cloned this repository. (i.e. /app/public/platformsh2slack)

See the sample file for the required settings: [platformsh2slack.sample.yaml](platformsh2slack.sample.yaml)

## Quick test

If all went ok, you should be able to access the script at your project's url:

`http://PROJECTURL/platformsh2slack/platformsh2slack.php`

And see `No config or valid token.` in the browser.

## Token

One of the necessary configuration is a random token you need to define as `PLATOFRMSH2SLACK_TOKEN` in the config file. This is only to prevent possible unauthorized use of the script. Once you define it, you have to add it the url for the integration.

i.e. If you define:

```yaml
token: '1234'
```

You would use 

`http://PROJECTURL/platformsh2slack/platformsh2slack.php?token=1234`

For your integration URL.

## Add the integration

Run the following:

```bash
platform integration:add --type=webhook --url="https://PROJECTURL/platformsh2slack/platformsh2slack.php?token=TOKEN"
```

Replacing **PROJECTURL** for your platform route and **TOKEN** with your defined token.

## Environoments

You can have this script on any environment, even master. As far as my trials went, even pushing to master works.
