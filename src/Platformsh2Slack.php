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

  /**
   * Instantiate a new Webhook adapter
   *
   * @param string $slack_endpoint
   * @param string $slack_channel
   * @param array $settings
   * @return void
   */
  function __construct($slack_endpoint, $slack_channel, array $settings = []) {
    // Default settings
    $this->config = $settings + [
      'region' => 'eu',
      'commit_limit' => 10,
      'routes' => false,
      'configurations' => false,
      'debug' => false,
    ];

    $this->request = Request::createFromGlobals();
  }


  function validate($token) {
    if ($token != $this->request->query->get('token')) {
      $response = new Response('Invalid token', 403);
      $response->send();
    }
  }

  function send() {


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
