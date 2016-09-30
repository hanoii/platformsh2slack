<?php

// Define some secret token that needs to be present in the configured platform
// webhook integration as a 'token' query string.
// This is just a simple technique to prevent unauthorized access of this script
define('PLATOFRMSH2SLACK_TOKEN', 'some-secret-token');

// The platform region where your project is hosted
define('PLATOFRMSH2SLACK_HOST', 'eu.platform.sh');

// The number of commit descriptions that you want to be included in the slack
// message
define('PLATOFRMSH2SLACK_COMMIT_LIMIT', 10);

// The channel where the message should be posted
define('PLATOFRMSH2SLACK_SLACK_CHANNEL', '#channel');

// The slack incoming webhook URL.
define('PLATOFRMSH2SLACK_SLACK_URL', 'https://hooks.slack.com/services/...');

// This scripts is a WIP and as such, some webhooks could change or not be
// currently known by the script.
// A message about it is sent to slack but if you want, a temp file
// with the json object could be saved to /tmp, so that this script can be
// improved.
define('PLATOFRMSH2SLACK_DEBUG', false);

