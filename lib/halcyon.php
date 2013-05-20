<?php
class Halcyon {
  const DEBUG = false;

  public $_templateDirectory = "views";
  const CONTROLLER_DIR = "app";

  //
  // Don't touch anything beyond this line :-)
  //
  // Really.
  //

  const RESPONSE_NONE = 0;
  const RESPONSE_JSON = 1;
  const RESPONSE_TEMPLATED = 2;

  private static $_halcyonInstance = null;

  public $_controllerLoaded = false;


  public $_responseType = Halcyon::RESPONSE_TEMPLATED;
  public $_templateLayout = "layout";
  public $_templateBody = null;
  public $_responseContentType = "text/html; charset=utf-8";
  
  
  public static function run() {
    $instance = self::getInstance();
    
    return $instance;
  }


  public static function getInstance() {
    if (self::$_halcyonInstance === null) {
      self::$_halcyonInstance = new Halcyon();
    }

    return self::$_halcyonInstance;
  }

  public static function noResponse() {
    self::getInstance()->_responseType = Halcyon::RESPONSE_NONE;
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


  public static function setTemplateDirectory($base) {
    self::getInstance()->_templateDirectory = $base;
  }

  public static function setLayout($layout) {
    self::getInstance()->_templateLayout = $layout;
  }

  public static function setBody($body) {
    self::getInstance()->_templateBody = $body;
  }


  public static function requireSession() {
    if (!isset($_SESSION["user"])) {
      header("Location: /session/login/");
      exit;
    }

    return true;
  }

  
  public static function requireGroup($group) {
    Halcyon::requireSession();
    
    if ($_SESSION["user"]->group != $group) {
      header("Location: /session/login/");
      exit;
    }

    return true;
  }

  
  public function __construct() {
    session_start();
    $this->_query = $_SERVER["QUERY_STRING"];

    // Clean up variables - URL
    $url = isset($_SERVER["REDIRECT_URL"]) ? $_SERVER["REDIRECT_URL"] : $_SERVER["REQUEST_URI"];

    $url = rtrim($url, '/');
    
    $scriptcount = count(explode("/", $_SERVER["SCRIPT_NAME"]));
    
    $explodedurl = explode("/", $url);
    $countexplodedurl = count($explodedurl);
      
    $sliced = array_slice($explodedurl, 0 + $scriptcount - 1);

    $this->_url = join("/", $sliced);
  }

  
  // General handler function
  public function handleRequest() {
    $this->loadController();

    if (!$this->_controllerLoaded) {
      header("HTTP/1.0 404 Not Found");

      echo "Error 404 :-(";
      
    } else {
      $this->_handler = new $this->_controllerClassname();

      $this->_controllerReturnValue = call_user_func_array(array($this->_handler, $this->_calleeMethod), $this->_calleeParams);

      $this->decorateOutput();
    }
  }


  // Decorate the output of the controller as per controller's request...
  private function decorateOutput() {
    if ($this->_responseType == Halcyon::RESPONSE_NONE) {
      // nothing, just nothing...
      
    } elseif ($this->_responseType == Halcyon::RESPONSE_JSON) {
      header("Content-Type: application/json");
      echo json_encode($this->_controllerReturnValue);
      
    } elseif ($this->_responseType == Halcyon::RESPONSE_TEMPLATED) {
      header("Content-Type: " . $this->_responseContentType);
      // We're throwing away the return value...

      $templatefilename = null;
      
      if ($this->_templateBody === null) {
	$this->_templateBody = ucfirst($this->_calleeMethod);
	
	$templatefilename = $this->_templateDirectory . "/" .
	  $this->_controllerFilename . $this->_templateBody . ".tpl";

      } else {
	$templatefilename = $this->_templateDirectory . "/" .
	  $this->_templateBody . ".tpl";
      }

      if ($templatefilename !== null) {
	$body = ___captureFileOutput($this->_handler, $templatefilename);


	if ($this->_templateLayout !== null) {
	  $values = (array) $this->_handler;
	  $values["___body___"] = $body;

	  $layout = ___captureFileOutput($values, $this->_templateDirectory . "/" . $this->_templateLayout . ".tpl");

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

    ___log("|_. Controller filename |_. Method name |_. Parameters |<br>\n");
    foreach ($candidates as $c) {
      ___log("| " . Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".php" . " | " . $c[1] . " | " . join(", ", $c[2]) . " |<br>\n");
      
      if ($last != $c[0]) {
	$last = $c[0];

	if (is_readable(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".php")) {
	  $classname = HalcyonClassMunger::import(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".php");
	  $loaded = true;

	} elseif (is_readable(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".yml")) {
	  $classname = HalcyonYamlMunger::import(Halcyon::CONTROLLER_DIR . "/" . $c[0] . ".yml");
	  $loaded = true;
	  
	} else {
	  $loaded = false;
	}
      }

      if ($loaded && $this->validateController($classname, $c[1], $c[2])) {
	$this->_controllerLoaded = true;

	$this->_controllerClassname = $classname;
	$this->_controllerFilename = $last;
	
	return;
      }
    }
  }


  private function validateController($classname, $method, $params) {
    if (is_callable(array($classname, $method))) {
      $mref = new ReflectionMethod($classname, $method);
      $pref = $mref->getParameters();

      if (count($params) >= $mref->getNumberOfRequiredParameters()) {
	if (count($params) >= $mref->getNumberOfParameters()) {
	  // The list of parameters has more than enough parameters to satisfy method.
	  if (count($pref) > 0 && $pref[count($pref)-1]->isArray()) {
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


class HalcyonClassMunger {
  public static function import($filename) {
    $classname = "Loaded_" . md5(uniqid("", true));

    self::wrapAndEval($classname, $filename);

    return $classname;
  }


  private static function wrapAndEval($classname, $filename) {
    $code = file_get_contents($filename);

    if (substr($code, 0, 5) == '<?php') {
        $code = substr($code, 5);

    } else {
        if (substr($code, 0, 2) == '<?') {
            $code = substr($code, 2);
        }
    }

    if (substr($code, -2) == '?>') {
        $code = substr($code, 0, strlen($code)-2);
    }

    $template = "class $classname {" . $code . "}";

    eval($template);
  }
}


class HalcyonYamlMunger {
  public static function import($filename) {
    $classname = "Loaded_" . md5(uniqid("", true));

    self::wrapAndEval($classname, $filename);

    return $classname;
  }

  private static function wrapAndEval($classname, $filename) {
    $template = "class $classname extends HalcyonSnowflake { public \$specifier = '$filename'; }";

    eval($template);
  }
}

function ___captureFileOutput($___object, $___filename) {
  if (is_readable($___filename)) {
    ob_start();

    extract((array) $___object, EXTR_SKIP);
    include($___filename);

    return ob_get_clean();
    
  } else {
    die("Cannot read template file $___filename!\n");
  }
}


function ___log($a) {
  if (Halcyon::DEBUG) {
    echo $a;
  }
}


function is_assoc(array $thing) {
  return array_values($thing) === $thing;
}


function evalFunction($body) {
  $name = "___function_" . md5($body);

  eval("function $name (\$record) { $body }");
  
  return $name;
}



class HalcyonSnowflake {
  //Halcyon::jsonResponse();
  //Halcyon::suppressLayout();
  //Halcyon::setLayout("layout2");
  //Halcyon::setBody("layout");

  private $defaultSpecifier = array(
				    "templating" => array(
							  "base" => "views/snowflakes",
							  "layout" => "layout"
							  )
  );
  
  public $specifier = null;

  public function __construct() {
    $this->specifier = Spyc::YAMLLoad($this->specifier);
    $this->specifier = array_merge($this->defaultSpecifier, $this->specifier);

    $this->__fixupSpecifier();

    Halcyon::setTemplateDirectory($this->specifier["templating"]["base"]);
    Halcyon::setLayout($this->specifier["templating"]["layout"]);
  }

  private function __fixupSpecifier() {
    foreach ($this->specifier['fields'] as $key => &$value) {
      if (!isset($value['type'])) {
	$value['type'] = 'readonly';
      }

      if (isset($value['type']) && $value['type'] == "image" && isset($value['to_url'])) {
	$value['to_url'] = evalFunction($value['to_url']);
      }
    }
  }
  
  public function index() {
    global $_rbDB, $_rbRB;
    Halcyon::setBody("index");

    $keys = $_rbDB->getCol("SELECT id FROM " . $this->specifier['bean']);
    $this->records = $_rbRB->batch($this->specifier['bean'], $keys);
  }

  public function edit($id) {
    global $_rbDB, $_rbRB;
    Halcyon::setBody("edit");

    $this->record = $_rbRB->load($this->specifier['bean'], $id);

    $keys = $_rbDB->getCol("SELECT id FROM " . $this->specifier['bean'] . " WHERE id<> " . $id . " ORDER BY name ASC");
    $this->tree_parents = $_rbRB->batch($this->specifier['bean'], $keys);
  }

  public function save($id) {
    global $_rbDB, $_rbRB;

    $record = $_rbRB->load($this->specifier['bean'], $id);

    header("Content-Type: text/plain");
    
    foreach ($_POST as $key => $value) {
      if (substr($key, 0, 5) == "form_") {
	$key = substr($key, 5);

	$record->$key = $value;
      }
    }

    $_rbRB->store($record);
  }
}
