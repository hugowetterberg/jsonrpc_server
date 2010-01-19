<?php
// $Id$

// References: 
// JSON-RPC 1.1 draft: http://json-rpc.org/wd/JSON-RPC-1-1-WD-20060807.html
// JSON-RPC 2.0 proposal: http://groups.google.com/group/json-rpc/web/json-rpc-1-2-proposal

// Error codes follows the json-rpc 2.0 proposal as no codes exists for the 1.1 draft
// -32700   Parse error.  Invalid JSON. An error occurred on the server while parsing the JSON text.
// -32600   Invalid Request.  The received JSON not a valid JSON-RPC Request.
// -32601   Method not found.   The requested remote-procedure does not exist / is not available.
// -32602   Invalid params.   Invalid method parameters.
// -32603   Internal error.   Internal JSON-RPC error.
// -32099..-32000   Server error.   Reserved for implementation-defined server-errors.
define('JSONRPC_ERROR_PARSE', -32700);
define('JSONRPC_ERROR_REQUEST', -32600);
define('JSONRPC_ERROR_PROCEDURE_NOT_FOUND', -32601);
define('JSONRPC_ERROR_PARAMS', -32602);
define('JSONRPC_ERROR_INTERNAL_ERROR', -32603);

class JsonRpcServer{
  private $id, $method, $in, $version, $major_version;
  private $service_method, $params, $args;
  
  public function __construct($in) {
    $this->in = $in;
    $this->method_name = isset($in['method']) ? $in['method'] : NULL;
    $this->id = isset($in['id']) ? $in['id'] : NULL;
    $this->version = isset($in['jsonrpc']) ? $in['jsonrpc'] : '1.1';
    $this->major_version = intval(substr($this->version, 0, 1));
    $this->params = isset($in['params']) ? $in['params'] : NULL;
  }
  
  public function handle() {
    //A method is required, no matter what
    if(empty($this->method_name)) {
      return $this->error(JSONRPC_ERROR_REQUEST, t("The received JSON not a valid JSON-RPC Request"));
    }
    
    $endpoint = services_get_server_info('endpoint');

    //Find the method
    $this->method = services_controller_get($this->method_name, $endpoint);
    $args = array();

    if (!isset($this->method)) { // No method found is a fatal error
      return $this->error(JSONRPC_ERROR_PROCEDURE_NOT_FOUND, t("Invalid method @method", 
        array('@method' => $request)));
    }
    
    //If needed, check if parameters can be omitted
    $arg_count = count($this->method['args']);
    if (!isset($this->params)) {
      for ($i=0; $i<$arg_count; $i++) {
        $arg = $this->method['#args'][$i];
        if (!$arg['optional']) {
          if (empty($this->params)) {
            // We have required parameter, but we don't have any.
            if (is_array($this->params)) {
              // The request has probably been parsed correctly if params is an array,
              // just tell the client that we're missing parameters.
              return $this->error(JSONRPC_ERROR_PARAMS, t("No parameters recieved, the method '@method' has required parameters.", 
                array('@method'=>$this->method_name)));
            }
            else {
              // If params isn't an array we probably have a syntax error in the json.
              // Tell the client that there was a error while parsing the json.
              // TODO: parse errors should be caught earlier
              return $this->error(JSONRPC_ERROR_PARSE, t("No parameters recieved, the likely reason is malformed json, the method '@method' has required parameters.", 
                array('@method'=>$this->method_name)));
            }
          }
        }
      }
    }
    
    // Map parameters to arguments, the 1.1 draft is more generous than the 2.0 proposal when
    // it comes to parameter passing. 1.1-d allows mixed positional and named parameters while 
    // 2.0-p forces the client to choose between the two.
    //
    // 2.0 proposal on parameters: http://groups.google.com/group/json-rpc/web/json-rpc-1-2-proposal#parameters-positional-and-named
    // 1.1 draft on parameters: http://json-rpc.org/wd/JSON-RPC-1-1-WD-20060807.html#NamedPositionalParameters
    if($this->array_is_assoc($this->params))
    {
      $this->args = array();
      
      //Create a assoc array to look up indexes for parameter names
      $arg_dict = array();
      for ($i=0; $i<$arg_count; $i++) {
        $arg = $this->method['args'][$i];
        $arg_dict[$arg['name']] = $i;
      }
      
      foreach ($this->params as $key => $value) {
        if ($this->major_version==1 && preg_match('/^\d+$/',$key)) { //A positional argument (only allowed in v1.1 calls)
          if ($key >= $arg_count) { //Index outside bounds
            return $this->error(JSONRPC_ERROR_PARAMS, t("Positional parameter with a position outside the bounds (index: @index) recieved", 
              array('@index'=>$key)));
          }
          else {
            $this->args[intval($key)] = $value;
          }
        }
        else { //Associative key
          if (!isset($arg_dict[$key])) { //Unknown parameter
            return $this->error(JSONRPC_ERROR_PARAMS, t("Unknown named parameter '@name' recieved", 
              array('@name'=>$key)));
          }
          else {
            $this->args[$arg_dict[$key]] = $value;
          }
        }
      }
    }
    else { //Non associative arrays can be mapped directly
      $param_count = count($this->params);
      if ($param_count > $arg_count) {
        return $this->error(JSONRPC_ERROR_PARAMS, t("Too many arguments recieved, the method '@method' only takes '@num' argument(s)", 
          array('@method'=>$this->method_name, '@num'=> $arg_count )));
      }
      $this->args = $this->params;
    }
    
    //Validate arguments
    for($i=0; $i<$arg_count; $i++)
    {
      $val = $this->args[$i];
      $arg = $this->method['args'][$i];
      
      if (isset($val)) { //If we have data
        if ($arg['type'] == 'struct' && is_array($val) && $this->array_is_assoc($val)) {
          $this->args[$i] = $val = (object)$val;
        }

        //Only array-type parameters accepts arrays
        if (is_array($val) && $arg['type']!='array'){
          return $this->error_wrong_type($arg, 'array');
        }
        //Check that int and float value type arguments get numeric values
        else if(($arg['type']=='int' || $arg['type']=='float') && !is_numeric($val)) {
          return $this->error_wrong_type($arg,'string');
        }
      }
      else if (!$arg['optional']) { //Trigger error if a required parameter is missing
        return $this->error(JSONRPC_ERROR_PARAMS, t("Argument '@name' is required but was not recieved", array('@name'=>$arg['name'])));
      }
    }
    
    // We are returning JSON, so tell the browser.
    drupal_set_header('Content-Type: application/json; charset=utf-8');

    //Call service method
    try {
      $result = services_controller_execute($this->method, $this->args);
      if (is_array($result) && isset($result['error']) && $result['error'] === TRUE) {
        return $this->error(JSONRPC_ERROR_INTERNAL_ERROR, $result['message']);
      }
      else {
        return $this->result($result);
      }
    } catch (Exception $e) {
      return $this->error(JSONRPC_ERROR_INTERNAL_ERROR, $e->getMessage());
    }
    
  }
  
  private function array_is_assoc(&$arr) {
    $count = count($arr);
    for ($i=0;$i<$count;$i++) {
      if (!array_key_exists($i, $arr)) {
        return true;
      }
    }
    return false;
  }
  
  private function response_version(&$response) {
    switch ($this->major_version) {
      case 2:
        $response['jsonrpc'] = '2.0';
        break;
      case 1:
        $response['version'] = '1.1';
        break;
    }
  }
  
  private function response_id(&$response) {
    if (!empty($this->id)) {
      $response['id'] = $this->id;
    }
  }
  
  private function result($result) {
    $response = array('result' => $result);
    return $this->response($response);
  }

  private function error($code, $message) {
    $response = array('error' => array('name' => 'JSONRPCError', 'code' => $code, 'message' => $message));
    return $this->response($response);
  }

  private function error_wrong_type(&$arg, $type){
    return $this->error(JSONRPC_ERROR_PARAMS, t("The argument '@arg' should be a @type, not @used_type",
        array(
          '@arg' => $arg['name'],
          '@type' => $arg['type'],
          '@used_type' => $type,
        )
    ));
  }
  
  private function response($response) {
    // Check if this is a 2.0 notification call
    if($this->major_version==2 && empty($this->id))
      return;
    
    $this->response_version($response);
    $this->response_id($response);
    
    //Using the current development version of Drupal 7:s drupal_to_js instead
    return str_replace(array("<", ">", "&"), array('\x3c', '\x3e', '\x26'), json_encode($response));
  }
}