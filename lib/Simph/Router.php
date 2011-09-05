<?php
/**
 * @author Francesco Terenzani <f.terenzani@gmail.com>
 * @copyright Copyright (c) 2011, Francesco Terenzani
 * @category Simph
 * @package Simph_Router
 */
/**
 * A simple router to map requests across file paths
 */
class Simph_Router 
{    

    /**
     * The HTTP path to your web root. The value is automatically obtained from
     * $_SERVER['SCRIPT_NAME'] within the constructor
     * @var string
     */
    public $web;

    /**
     * Last matched request
     * @var string
     */
    public $request;

    /**
     * Path that match the request
     * @var string
     */
    public $pagePath;
    
    /**
     * Front controller file name. Leave it blank if you are using
     * mod_rewrite to remove it on your URLs.
     * @var string
     */
    protected $frontFile;
    
    /**
     * Parameters defined globally
     * @var array
     */
    protected $globalDefinitions = array();

    /**
     * Array of defined routes
     * @var array
     */
    protected $routes = array();
    
    /**
     * @param string $frontFile  Front controller file name. Leave it blank
     *                           if you are using mod_rewrite to remove it on 
     *                           your URLs.
     */
    function  __construct($frontFile = '') {

        $this->frontFile = $frontFile;
        $this->web = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

    }
    
    /**
     * Define a parameter regex and its default value globally
     * 
     * @param string $name        Parameter name
     * @param string $definition  Regex definition
     * @param mixin $default      Default value
     * @return Simph_Router 
     */
    function def($name, $definition, $default = null) {
        
        $this->globalDefinitions[$name] = array($definition, $default);
        return $this;
        
    }
    
    /**
     * Define a route
     * 
     * @param string $pattern     Pattern of the request. For example
     *                            /users/:id
     * @param string $pagePath    Page path to return if the pattern match the
     *                            request
     * @return Simph_Router_Route 
     */
    function route($pattern, $pagePath) {
        $route = new Simph_Router_Route($pattern);
        $pagePath = ltrim($pagePath, '/');
        
        foreach ($this->globalDefinitions as $name => $def) {
            $route->def($name, $def[0], $def[1]);
        }
        
        $this->routes[$pagePath] = $route;
        
        return $route;
                
    }
    
    /**
     * Return the page path based on the request ($_SERVER['REQUEST_URI'])
     * 
     * @return string                      Page path that match the request
     * @throws Simph_Router_HttpException  if the request contains illegal
     *                                     characters or if the request should
     *                                     be redirected but a parameter that is
     *                                     riquired to obtain the new location
     *                                     is not provided
     */    
    function matchRequest() {
        $request = explode('?', $_SERVER['REQUEST_URI']);
        $request = preg_replace('#' . preg_quote($this->web, '#') 
                . '(?:' . $this->frontFile . '/)?(.*)#', "/$1", $request[0]);
        
        return $this->match($request);
        
    }
    
    /**
     * Return the page path based on the request given as argument
     *
     * @param string $request              Request
     * @return string                      Page path that match the request
     * @throws Simph_Router_HttpException  if the request contains illegal
     *                                     characters or if the request should
     *                                     be redirected but a parameter that is
     *                                     riquired to obtain the new location
     *                                     is not provided
     */
    function match($request) {

        if (empty($request) || $request[0] !== '/') {
            throw new Simph_Router_HttpException('Bad Request', 400);
        }
        
        $this->request = $request;

        // If none route match the request
        if (!($path = $this->routeMatch($request))) {

            $path = $this->filterPath($request);
            
            // Redirect request that ends with index to have consistent URLs
            if (substr($path, -5) === 'index') {
                $this->redirect($this->urlFor($path . '.php', $_GET), '301');

            }

            // Add index to path that end with /
            if (substr($path, -1) === '/') {
                $path .= 'index';
            }

            // Add extension and remove the / as prefix
            $path .= '.php';
            $path = ltrim($path, '/');

            // The path match to a rewrited one
            if (isset($this->routes[$path])) {
                // Redirect the path to the rewrited version
                try {
                    $this->redirect($this->urlFor($path, $_GET), '301');

                } catch (Simph_Router_Exception $e) {
                    throw new Simph_Router_HttpException('Not Found', 404);

                }

            }
        }

        return $this->pagePath = $path;

    }
    
    /**
     * Create the absolute URL of a page without hostname
     * 
     * @param string $path           The page path that have to be
     *                               transformed in a routed path
     * @param array|object $params   Parameters of the URL
     * @return string                The absolute URL of the page
     */
    function pathFor($path, $params = array()) {
        
        // Shorthand for the home page
        if ($path === 'home') {

            $path = '/';

        } else {
            $path = ltrim($path, '/');

            if (isset($this->routes[$path])) {
                $path = $this->routes[$path]->getPath($params);

            } else {
                $path = '/' . preg_replace('#(?:index)?.php$#', '', $path);
                
                if ($params) {
                    $path .= '?' . http_build_query($params);
                    
                }

            }

        }

        // Create the link
        if (!$this->frontFile) {
            $path = ltrim($path, '/');
        }

        if ($this->frontFile === 'index.php' && $path === '/') {

            return $this->web;


        } else {

            return $this->web . $this->frontFile . $path;

        }

    }
    
    /**
     * Create the absolute URL of a page including the hostname
     * 
     * @param string $path           The page path that have to be
     *                               transformed in a routed path
     * @param array|object $params   Parameters of the URL
     * @param string $schema         Schema to use as prefix of the URL
     * @return string                The absolute HTTP URL of the page
     */
    function urlFor($path, $params = array(), $schema = 'http://') {        
        $port = isset($_SERVER['HTTP_PORT'])? ':' . $_SERVER['HTTP_PORT']: '';
        return $schema . $_SERVER['HTTP_HOST'] . $port . $this->pathFor($path, $params);

    }

    /**
     * HTTP redirect
     *
     * @param string $url         Location
     * @param string|int $status  Status code
     */
    function redirect($url, $status = '302') {
        header('Location: ' . $url, true, $status);
        die;

    }

    /**
     * Check if the request match a route
     * @param string $request
     * @return bool|string      The page path if a route match, false otherwise
     */
    protected function routeMatch($request) {
        foreach ($this->routes as $path => $route) {
            if (false !== ($params = $route->match($request))) {
                // Update $_GET
                $_GET = $params + $_GET;
                return $this->filterPath($path);
            }
        }
    }

    /**
     * Replace null bytes and check for illegal chars
     *
     * @param string $path
     * @return string
     * @throws Simph_Router_HttpException if path contains illegal chars
     */
    protected function filterPath($path) {
        // Paths may not contain "/_" or "\\_" because file starting with _
        // identify a partial page, nor parent directory traversal
        // ("../", "..\\" notation) to avoid Local File Inclusion (LFI) vulnerability
        if (preg_match('#\.\.[\\\/]|[\\\/]_#', $path)) {
            throw new Simph_Router_HttpException('Bad Request', 400);
        }

        // Let's remove null bytes if any
        // http://www.php.net/manual/en/security.filesystem.nullbytes.php
        return str_replace("\0", '', $path);

    }
    
    
}



/**
 * Define a single route
 */
class Simph_Router_Route
{

    /**
     * The pattern of the request
     * @var string
     */
    protected $pattern;
    
    /**
     * Default paremeter values
     * @var array
     */
    protected $defaults = array();
    
    /**
     * Array of regular expressions describing each parameter that need to
     * override the $defaultDefinition
     * @var array 
     */
    protected $definitions = array();
    
    /**
     * Default regex definition of a parameter in the request
     * @var string
     */
    protected $defaultDefinition = '[A-Za-z0-9_-]+';


    /**
     * Scalar array of possible paremeters
     * @var array
     */
    protected $variables = array();
    
    /**
     * Scalar array of optional paremeter sub patterns. 
     * For example "(page/:page)"
     * @var array
     */
    protected $subPatterns = array();
    
    /**
     * Route implements a lazy setup. This var is used to know if the object is
     * already set up.
     * @var null|bool  True after set up
     */
    protected $done;
    
    /**
     * The regular expression to preg_match the request
     * @var string
     */
    protected $regex;
    
    
    /**
     * @param string $pattern 
     */
    function __construct($pattern) {
        $this->pattern = $pattern[0] === '/'? $pattern: '/' . $pattern;

    }
    
    /**
     * Define a parameter regex and/or its default value
     * 
     * @param string $param       The parameter name
     * @param string $definition  A regex defining the parameter
     * @param mixin $default      The default value of the parameter
     * @return Simph_Router_Route 
     */
    function def($param, $definition, $default = null) {
        if ($definition) {
            $this->definitions[$param] = $definition;
        }
        
        if ($default) {
            $this->defaults[$param] = $default;
            
        }
        
        return $this;
        
    }
    
    /**
     * The function return false if the given request doesn't match to the route 
     * pattern otherwise an array with the parameter values
     * 
     * @param string $request 
     * @return bool|array
     */
    function match($request) {
                
        /*
         * Pattern that donesn't contain parameter placeholders doesn't need to 
         * match the request with a regular expression 
         */
        if (strpos($this->pattern, ':') === false) {

            $this->done = true;            

            return $this->pattern === $request? $this->defaults: false;

        }
        
        /*
         * Set up the regular expression to preg_match the request
         */
        $this->setUp();

        if (preg_match("#^" . $this->regex . "$#U", $request, $match)) {

            $params = $this->defaults;

            /*
             * shift the entire request from the first position
             */
            array_shift($match);
            for ($i = 0, $j = count($match); $i < $j; $i++) {
                if (isset($this->variables[$i])) {
                    $params[$this->variables[$i]] = $match[$i];

                }

            }

            return $params;

        }

        return false;

    }
    
    /**
     * Fill out the pattern with the given arguments and return the path to
     * link this route.
     * 
     * Placeholders in the pattern need to match the keys/properties of the 
     * array or object given as first argument. If the first argument is 
     * an object and no property match with a placeholder, the function check 
     * for a method getCamelizedVariableName. 
     * 
     * If a required variable is not given, the function throws an exception.
     * 
     * @param array|stdClass $values         Parameters to use in the link
     * @return string                        Path to link this route
     * @throws Simph_Router_Route_Exception  if a required variable is not given
     */
    function getPath($values = array()) {

        $this->setUp();

        $pattern = $this->pattern;
        
        if (is_object($values)) {
            $arr_values = (array) $values;
            
        } else {
            $arr_values = $values;
            
        }
        

        foreach ($this->variables as $var) {
            
            $str = null;
            $replace = null;

            if (isset($arr_values[$var])) {
                $str = ":$var";
                $replace = $arr_values[$var];

            } elseif (is_object($values)) {

                $method = 'get' . implode('',
                        array_map('ucwords', explode('_', strtolower($var))));

                if (method_exists($values, $method)) {
                    $str = ":$var";
                    $replace = $values->{$method}();

                }

            } elseif (isset($this->subPatterns[$var])) {
                $str = $this->subPatterns[$var];
                $replace = '';

            } else {

                throw new Simph_Router_Exception("Undefined required variable $var");

            }


            if (isset($str)) {
                $pattern = str_replace($str, $replace, $pattern);

            }

        }

        return preg_replace('#\(|\)#', '', $pattern);

    }
    
    /**
     * Set up the regex to match the request, the definitions of each variable,
     * the variable list itself and the list of optional sub patterns
     */
    protected function setUp() {

        if (!isset($this->done)) {

            if (strpos($this->pattern, ':') !== false) {                
                
                $pattern = $this->pattern;

                // Check for optional sub patterns
                if (preg_match_all('#\(.*:([\w_]+).*\)#', $pattern, $match)) {

                    for ($i = 0, $j = count($match[0]); $i < $j; ++$i) {

                        $pattern = str_replace($match[0][$i], '%s', $pattern);

                        // array('page' => '(page/:page)')
                        $this->subPatterns[$match[1][$i]] = $match[0][$i];

                    }

                }

                $pattern = preg_quote($pattern, '#');

                if ($this->subPatterns) {
                    $pattern = vsprintf($pattern, array_map(
                            array($this, 'subPatternToRegex'), $this->subPatterns));
                }

                $regex = preg_replace_callback('#:[\w_]+#',
                        array($this, 'setRegex'),
                        str_replace('\\:', ':', $pattern));
                
                $this->regex = $regex;

            }

            $this->done = true;

        }

    }
    
    /**
     * Callback used in $this->setUp() as argument of preg_replace_callback
     * 
     * @param array $match Param placeholder in the form :param_name
     * @return string      Sub regex to match the single parameter
     */
    protected function setRegex($match) {

        $regex = $this->defaultDefinition;

        $var = substr($match[0], 1);
        $this->variables[] = $var;
        if (isset($this->definitions[$var])) {
            $regex = $this->definitions[$var];

        }

        return '(' . $regex . ')';


    }

    /**
     * @param string $subPattern  For example (page/:page)
     * @return string             (?:page/:page)?
     */
    protected function subPatternToRegex($subPattern) {
        return str_replace(array('\\(', '\\)', '\\:'), array('(?:', ')?', ':'),
                preg_quote($subPattern));
    }

}

class Simph_Router_Exception extends Exception
{

}

class Simph_Router_HttpException extends Simph_Router_Exception
{

}