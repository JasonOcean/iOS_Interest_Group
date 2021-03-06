<?php

/**
 *  This file is part of amfPHP
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file license.txt.
 * @package Amfphp_Plugins_ServiceBrowser
 */

/**
 * A simple service browser with html only. Sometimes you don't need the full thing with AMF etc., so use this
 * This plugin should not be deployed on a production server.
 * 
 * call the gateway with the following GET parameters:
 * serviceName: the service name
 * methodName : the method to call on the service
 *
 * pass the parameters as POST data. Each will be JSON decoded to be able to pass complex parameters. This requires PHP 5.2 or higher
 *
 * @package Amfphp_Plugins_ServiceBrowser
 * @author Ariel Sommeria-Klein
 */
class AmfphpServiceBrowser implements Amfphp_Core_Common_IDeserializer, Amfphp_Core_Common_IDeserializedRequestHandler, Amfphp_Core_Common_IExceptionHandler, Amfphp_Core_Common_ISerializer {
    /**
     * if content type is not set or content is set to "application/x-www-form-urlencoded", this plugin will handle the request
     */
    const CONTENT_TYPE = "application/x-www-form-urlencoded";

    private $debug = NULL;
    private $serviceName;

    private $methodName;

    /**
     * used for service call
     * @var array
     */
    private $parameters;

    /**
     * associative array of parameters. Used to set the parameters input fields to the same values again after a call.
     * note: stored encoded because that's the way we need them to show them in the dialog
     * @var array
     */
    private $parametersAssoc;

    private $serviceRouter;

    private $showResult;

    /**
     * constructor.
     * @param array $config optional key/value pairs in an associative array. Used to override default configuration values.
     */
    public function __construct(array $config = null) {
        $filterManager = Amfphp_Core_FilterManager::getInstance();
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_DESERIALIZER, $this, "filterHandler");
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_DESERIALIZED_REQUEST_HANDLER, $this, "filterHandler");
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_EXCEPTION_HANDLER, $this, "filterHandler");
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_SERIALIZER, $this, "filterHandler");
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_HEADERS, $this, "filterHeaders");
    }

    /**
     * if no content type, then returns this. 
     * @param mixed null at call in gateway.
     * @param String $contentType
     * @return this or null
     */
    public function filterHandler($handler, $contentType) {
        if (!$contentType || $contentType == self::CONTENT_TYPE) {
            return $this;
        }
    }

    /**
     * @see Amfphp_Core_Common_IDeserializer
     */
    public function deserialize(array $getData, array $postData, $rawPostData) {
        $ret = new stdClass();
        $ret->get = $getData;
        $ret->post = $postData;
        return $ret;
    }

    /**
     * adds an item to an array if and only if a duplicate is not already in the array
     * @param array $targetArray
     * @param <type> $toAdd
     * @return array
     */
    private function addToArrayIfUnique(array $targetArray, $toAdd) {
        foreach ($targetArray as $value) {
            if ($value == $toAdd) {
                return $targetArray;
            }
        }
        $targetArray[] = $toAdd;
        return $targetArray;
    }

    /**
     * returns a list of available services
     * @return array of service names
     */
    private function getAvailableServiceNames(array $serviceFolderPaths, array $serviceNames2ClassFindInfo) {
        $ret = array();
        foreach ($serviceFolderPaths as $serviceFolderPath) {
            $folderContent = scandir($serviceFolderPath);

            if ($folderContent){
                foreach ($folderContent as $fileName) {
                    //add all .php file names, but removing the .php suffix
                    if (strpos($fileName, ".php")) {
                        $serviceName = substr($fileName, 0, strlen($fileName) - 4);
                        $ret = $this->addToArrayIfUnique($ret, $serviceName);
                    }
                }
            }
        }

        foreach ($serviceNames2ClassFindInfo as $key => $value) {
            $ret = $this->addToArrayIfUnique($ret, $key);
        }

        return $ret;
    }

    /**
     * @see Amfphp_Core_Common_IDeserializedRequestHandler
     */
    public function handleDeserializedRequest($deserializedRequest, Amfphp_Core_Common_ServiceRouter $serviceRouter) {
        $this->serviceRouter = $serviceRouter;

        if (isset($deserializedRequest->get["serviceName"])) {
            $this->serviceName = $deserializedRequest->get["serviceName"];
        }

        if (isset($deserializedRequest->get["methodName"])) {
            $this->methodName = $deserializedRequest->get["methodName"];
        }


        //if a method has parameters, they are set in post. If it has no parameters, set noParams in the GET.
        //if neither case is applicable, an error message with a form allowing the user to set the values is shown
        $paramsGiven = false;
        if (isset($deserializedRequest->post) && $deserializedRequest->post != null) {
            $this->parameters = array();
            $this->parametersAssoc = array();
            //try to json decode each parameter, then push it to $thios->parameters
            $numParams = count($deserializedRequest->post);
            foreach($deserializedRequest->post as $key => $value) {
                $this->parametersAssoc[$key] = $value;
                $decodedValue = json_decode($value);
                $valueToUse = $value;
                if($decodedValue){
                    $valueToUse = $decodedValue;
                }
                $this->parameters[] = $valueToUse;
            }
            $paramsGiven = true;
        } else if (isset($deserializedRequest->get["noParams"])) {
            $this->parameters = array();
            $paramsGiven = true;
            //note: use $paramsGiven because somehow if $$this->parameters contains an empty array, ($this->parameters == null) is true. 
        }
        
        if($this->serviceName && $this->methodName && $paramsGiven){
            $this->showResult = true;
            return $serviceRouter->executeServiceCall($this->serviceName, $this->methodName, $this->parameters);
        }else{
            $this->showResult = false;
            return null;
        }
    }

    /**
     * @todo show stack trace
     * @see Amfphp_Core_Common_IExceptionHandler
     */
    public function handleException(Exception $exception) {
        $exceptionInfo = "Exception thrown\n<br>";
        $exceptionInfo .= "message : " . $exception->getMessage() . "\n<br>";
        $exceptionInfo .= "code : " . $exception->getCode() . "\n<br>";
        $exceptionInfo .= "file : " . $exception->getFile() . "\n<br>";
        $exceptionInfo .= "line : " . $exception->getLine() . "\n<br>";
            //$exceptionInfo .= "trace : " . str_replace("\n", "<br>\n", print_r($exception->getTrace(), true)) . "\n<br>";
        $this->showResult = true;
       return $exceptionInfo;
    }

    /**
     * @see Amfphp_Core_Common_ISerializer
     */
    public function serialize($data) {
        $availableServiceNames = $this->getAvailableServiceNames($this->serviceRouter->serviceFolderPaths, $this->serviceRouter->serviceNames2ClassFindInfo);
        $message = file_get_contents(dirname(__FILE__) . "/Top.html");
        foreach ($availableServiceNames as $availableServiceName) {
            $message .= "\n     <li><a href='?serviceName=$availableServiceName'>$availableServiceName</a></li>";
        }
        $message .= "\n</ul>";

        if($this->serviceName){
            $serviceObject = $this->serviceRouter->getServiceObject($this->serviceName);
            $reflectionObj = new ReflectionObject($serviceObject);
            $availablePublicMethods = $reflectionObj->getMethods(ReflectionMethod::IS_PUBLIC);

            $message .= "<h3>Click below to use a method on the $this->serviceName service</h3>";
            $message .= "\n<ul>";
            foreach ($availablePublicMethods as $methodDescriptor) {
            	if ($methodDescriptor->isConstructor() == FALSE)
            	{
                	$availableMethodName = $methodDescriptor->name;
                	$message .= "\n     <li><a href='?serviceName=$this->serviceName&methodName=$availableMethodName'>$availableMethodName</a></li>";
            	}
            }
            $message .= "\n</ul>";
        }

        if($this->methodName){
            $serviceObject = $this->serviceRouter->getServiceObject($this->serviceName);
            $reflectionObj = new ReflectionObject($serviceObject);
            $method = $reflectionObj->getMethod($this->methodName);
            $parameterDescriptors = $method->getParameters();
            
            $methodDoc = $this->processPHPDoc($method);
            
            $message .= "<h3>Method Description</h3>";
            $message .= $methodDoc['description'];
            
        	// return value
        	$message .= "<h3>Method Return Type</h3>";
            if (isset($methodDoc['return']))
            {
            	$json2htmlurl = '../json2html/index.php?content=';
            	$name = $methodDoc['return']['type'];
            	
				/*
            	$sample = trim(file_get_contents( '../json/Sample/' . $name . '.json'));
            	$schema = trim(file_get_contents( '../json/Schema/' . $name . '.schema'));
                $message .= '<p>Return Type: ' . $name . '</p>';
               	$message .= '<p>Sample <a href=' . $json2htmlurl . base64_encode($sample) . '>View Detail</a><p>';
               	$message .= '<pre id="sample">' . $sample . '</pre>';
               	$message .= '<p>Schema <a href=' . $json2htmlurl . base64_encode($schema) . '>View Detail</a><p>';
               	$message .= '<pre id="schema">' . $schema . '</pre>';
				*/
            }
             else
             {
             	$message .= '<p>Warning: Missing return object description in comments</p>';
             }
            
            if (count($parameterDescriptors) > 0) {
                $message .= "<h3>Fill in the parameters below then click to call the $this->methodName method on $this->serviceName service</h3>";
                
                $message .= "\n<form action='?serviceName=$this->serviceName&methodName=$this->methodName' method='POST'>\n<table>";
                foreach ($parameterDescriptors as $parameterDescriptor) {
                    $availableParameterName = $parameterDescriptor->name;
                    $typeinfo = null;
                    $status = '';
					
					if(is_array($methodDoc['params']))
					{
						foreach($methodDoc['params'] as $parametersDocs)
						{
							if ($parametersDocs['name'] == $availableParameterName)
							{
								$typeinfo = $parametersDocs['type'];
							}
						}
					}
                    if ($typeinfo == null)
                    {
                    	$typeinfo = 'Unknown';
                    	$status = 'Warning: Parameter description in method and comments are not consistent';
                    }
                    
                    $message .= "\n     <tr><td>$typeinfo</td><td>$availableParameterName</td><td><input name='$availableParameterName' ";
                  	if($this->parametersAssoc){
                       	$message .= "value='" . $this->parametersAssoc[$availableParameterName] . "'";
                    }
                    $message .= "></td><td>$status</td></tr>";
                }
                $message .= "\n</table>\n<input type='submit' value='call'></form>";
            } else {
                $message .= "<h3>This method has no parameters. Click to call it.</h3>";
                $message .= "\n<form action='?serviceName=$this->serviceName&methodName=$this->methodName&noParams' method='POST'>\n";
                $message .= "\n<input type='submit' value='call'></form>";
            }
        }

        if($this->showResult){
            $message .= "<h3>Result</h3>";
            $message .= "<pre>";
            $message .= print_r($data, true);
            $message .= "</pre>";
        }
        $message .= file_get_contents(dirname(__FILE__) . "/Bottom.html");

        
        return $message;  
    }

    
    /**
     * filter the headers to make sure the content type is set to text/html if the request was handled by the service browser
     * @param array $headers
     * @return array
     */
    public function filterHeaders($headers, $contentType){
        if (!$contentType || $contentType == self::CONTENT_TYPE) {
            $headers["Content-Type"] = "text/html";
            return $headers;
        }
    }
    
    /**
     * 
     * Enter description here ...
     * @param ReflectionMethod $reflect
     * @return NULL|Ambigous <multitype:Ambigous <> , multitype:multitype: NULL multitype:Ambigous <>  >
     */
    public function processPHPDoc(ReflectionMethod $reflect)
	{
	    $phpDoc = array('params' => array(), 'return' => null, 'debug' => null, 'description' => '');
	    $docComment = $reflect->getDocComment();
	    if (trim($docComment) == '') {
	        return null;
	    }
	    $docComment = preg_replace('#[ \t]*(?:\/\*\*|\*\/|\*)?[ ]{0,1}(.*)?#', '$1', $docComment);
	    $docComment = ltrim($docComment, "\r\n");
	    $parsedDocComment = $docComment;
	    
	    //DEBUG
	    if (isset($this->debug))
	    {
	    	$phpDoc['debug'] = $parsedDocComment;
	    }
	    
	    $lineNumber = $firstBlandLineEncountered = 0;
	    $newlinePos = 0;
	    while (($newlinePos = strpos($parsedDocComment, "\n")) !== false) {
	        $lineNumber++;
	        $line = substr($parsedDocComment, 0, $newlinePos);
	        
	        if (strlen(trim($line)) < 1)
	        {
	        	break;
	        }
        	if (isset($this->debug))
	       	{
	       		echo '<p>Line ... :##' . $line . '## size: ' . strlen($line) . ' ^^ ' . $newlinePos  . '</p>';
	        }
	        	
	        $matches = array();
	        if ((strpos($line, '@') === 0) && (preg_match('#^(@\w+.*?)(\n)(?:@|\r?\n|$)#s', $parsedDocComment, $matches))) {
	            
	        	if (isset($this->debug))
	        	{
	        		echo '<p>Match ... : ' . $matches[1] . '</p>';
	        	}
	        	$tagDocblockLine = $matches[1];
	            $matches2 = array();
	
	            if (!preg_match('#^@(\w+)(\s|$)#', $tagDocblockLine, $matches2)) {
	                break;
	            }
	            $matches3 = array();
	            if (!preg_match('#^@(\w+)\s+([\w|\\\]+)(?:\s+(\$\S+))?(?:\s+(.*))?#s', $tagDocblockLine, $matches3)) {
	                break;
	            }
	            if ($matches3[1] != 'param') {
	                if (strtolower($matches3[1]) == 'return') {
	                    $phpDoc['return'] = array('type' => $matches3[2]);
	                }
	            } else {
	                $phpDoc['params'][] = array('name' => trim($matches3[3], '$'), 'type' => $matches3[2]);
	            }
	
	            $parsedDocComment = str_replace($matches[1] . $matches[2], '', $parsedDocComment);
	        }
	        else
	        {
	        	$phpDoc['description'] .= '<p>' . trim($line, '*') . '</p>';
	        	
	        	$parsedDocComment = str_replace($line, '', $parsedDocComment);
	        	$parsedDocComment = trim($parsedDocComment); 	
	        }
	        
	    	if (isset($this->debug))
	        {
	        	echo '<p>Description changed to: ' . $phpDoc['description'] . '</p>';
	        	echo '<p>$parsedDocComment changed to: ' . $parsedDocComment . '</p>';
	        }
	        
	        $parsedDocComment .= "\r\n";
	    }
	    return $phpDoc;
	}
}

?>
