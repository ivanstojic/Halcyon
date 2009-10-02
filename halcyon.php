<?php
class Halcyon {
  const CONTROLLER_DIR = "app";
  const TEMPLATE_DIR = "views";

  // Don't touch anything beyond this line :-)
  const RESPONSE_JSON = 1;
  const RESPONSE_TEMPLATED = 2;

  private static $_halcyonInstance = null;

  public $_controllerLoaded = false;


  public $_responseType = Halcyon::RESPONSE_TEMPLATED;
  public $_templateLayout = "layout.php";
  public $_templateBody = null;
  
  
  public static function run() {
    return self::getInstance();
  }


  public static function getInstance() {
    if (self::$_halcyonInstance === null) {
      self::$_halcyonInstance = new Halcyon();
    }

    return self::$_halcyonInstance;
  }


  public static function jsonResponse() {
    self::getInstance()->_responseType = Halcyon::RESPONSE_JSON;
  }


  public static function templatedResponse() {
    self::getInstance()->_responseType = Halcyon::RESPONSE_TEMPLATED;
  }


  public static function suppressLayout() {
    self::getInstance()->_templateLayout = null;
  }


  public static function setLayout($layout) {
    self::getInstance()->_templateLayout = $layout . ".php";
  }


  public static function setBody($body) {
    self::getInstance()->_templateBody = $body;
  }

  
  public function __construct() {
    $this->_query = $_SERVER["QUERY_STRING"];

    // Clean up variables - URL
    $url = $_SERVER["REDIRECT_URL"];
    
    $scriptcount = count(explode("/", $_SERVER["SCRIPT_NAME"]));
    
    $explodedurl = explode("/", $url);
    $countexplodedurl = count($explodedurl);
      
    $sliced = array_slice($explodedurl, 0 + $scriptcount - 1);

    $this->_url = join("/", $sliced);
  }

  
  // General handler function
  public function handleRequest() {
    $this->loadController();
    $this->initOutputDecoration();

    if (!$this->_controllerLoaded) {
      echo "Error 404 :-(";
    } else {
      $this->_handler = new Handler();

      $this->_controllerReturnValue = call_user_func_array(array($this->_handler, $this->_calleeMethod), $this->_calleeParams);

      $this->decorateOutput();
    }
  }


  private function initOutputDecoration() {
    $this->_templateBody = $this->_calleeMethod;
  }


  // Decorate the output of the controller as per controller's request...
  private function decorateOutput() {
    if ($this->_responseType == Halcyon::RESPONSE_JSON) {
      echo json_encode($this->_controllerReturnValue);
      
    } elseif ($this->_responseType == Halcyon::RESPONSE_TEMPLATED) {
      // We're throwing away the return value...

      if ($this->_templateBody !== null) {
	$this->_templateBody = ucfirst($this->_templateBody);
      
	$bodytpl = Halcyon::TEMPLATE_DIR . "/" .
	  $this->_controllerFilename . $this->_templateBody . ".php";

	$body = ___captureFileOutput($this->_handler, $bodytpl);

	if ($this->_templateLayout !== null) {
	  $values = (array) $this->_handler;
	  $values["___body___"] = $body;
	  
	  $layout = ___captureFileOutput($values, Halcyon::TEMPLATE_DIR . "/" . $this->_templateLayout);

	  echo $layout;
	  
	} else {
	  echo $body;
	}
      }
    }
  }



  // Get an array of arrays (file, method, params), load each in turn and try to find a valid one
  private function loadController() {
    $candidates = $this->findControllerCandidates();

    $last = "";
    $loaded = false;
    
    foreach ($candidates as $c) {
      if ($last != $c[0]) {
	$last = $c[0];

	if (is_readable(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".php")) {
	  runkit_import(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".php", RUNKIT_IMPORT_CLASSES);

	  $loaded = true;
	  
	} else {
	  $loaded = false;
	}
      }

      if ($loaded && $this->validateController($c[1], $c[2])) {
	$this->_controllerLoaded = true;
	$this->_controllerFilename = $last;
	
	return;
      }
    }
  }


  private function validateController($method, $params) {
    if (is_callable(array("Handler", $method))) {
      $mref = new ReflectionMethod("Handler", $method);
      $pref = $mref->getParameters();

      if (count($params) >= $mref->getNumberOfRequiredParameters()) {
	if (count($params) >= $mref->getNumberOfParameters()) {
	  // The list of parameters has more than enough parameters to satisfy method.
	  if ($pref[count($pref)-1]->isArray()) {
	    // We can wrap the rest-params in an array...
	    $count = count($params) - $mref->getNumberOfParameters() + 1;
	    $rest = array_splice($params, $mref->getNumberOfParameters() - 1);
	    $params[] = $rest;

	    $this->fixCalleeInfo($method, $params);
	      
	    return true;
	    
	  } else {
	    // The handler method won't accept rest-params as array.
	    if (count($params) == $mref->getNumberOfParameters()) {
	      // All parameters are filled, no rest-params
	      $this->fixCalleeInfo($method, $params);
	      return true;
	      
	    } else {
	      return false;
	    }
	  }
	  
	} else {
	  // List of params has enough params to satisfy the handler...
	  $this->fixCalleeInfo($method, $params);
	  return true;
	}
      }
    }

    return false;
  }


  // Store the method/params to properties, after the validator munges them...
  private function fixCalleeInfo($method, $params) {
    $this->_calleeMethod = $method;
    $this->_calleeParams = $params;
  }


  // Given a list of possible file names, generate variations based on the method name, etc...
  private function findControllerCandidates() {
    $candidates = $this->findControllerFileCandidates();

    $results = array();

    foreach ($candidates as $c) {
      $file = $c[0];
      $args = $c[1];

      if (count($args) > 0) {
	$method = $args[0];
	$rest = array_slice($args, 1);

	$results[] = array($file, $method, $rest);
      }
      
      $results[] = array($file, "index", $args);
    }

    return $results;
  }


  // Given the request URL, try to get a list of files that might contain a handler and extract
  // the remaining URL as REST parameters
  private function findControllerFileCandidates() {
    if ($this->_url === "") {
      $segments = array();
    } else {
      $segments = explode("/", $this->_url);
    }
    
    $rest = array();

    $results = array();

    while (count($segments) > 0) {
      // Segments are a directory, ie tack on index.php as filename at the end
      $candidate = join("/", $segments) . "/index";
      $results[] = array($candidate, $rest);

      // Segments are a directory + file (last segment gets a php extension)
      $candidate = join("/", $segments);
      $results[] = array($candidate, $rest);

      array_unshift($rest, array_pop($segments)); 
    }

    $results[] = array("index", $rest);

    return $results;
  }
}


function ___captureFileOutput($___object, $___filename) {
  ob_start();

  extract((array) $___object, EXTR_SKIP);
  include($___filename);

  return ob_get_clean();
}

