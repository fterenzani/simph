<?php
/**
 * @author Francesco Terenzani <f.terenzani@gmail.com>
 * @copyright Copyright (c) 2011, Francesco Terenzani
 */

/**
 * Simph_Router implements a routing system for no MVC architecture
 * 
 * Simph_Router maps requests across file paths allowing parameter 
 * placeholders
 * 
 * The simplest usage:
 * <code>
 * require '/path/to/Simph/Router.php';
 * 
 * $router = new Simph_Router;
 * 
 * // HTTP path to your front controller
 * echo $router->web;
 * 
 * // path that match the request
 * $path = $router->matchRequest();
 * 
 * // The absolute path to show the post with the id = 7
 * $link = $router->pathFor('posts/show.php', array('id' => 7));
 * // $link is /http/path/to/frontcontroller/posts/show?id=7
 * 
 * // The absolute URL to show the post with the id = 7
 * $link = $router->urlFor('posts/show.php', array('id' => 7));
 * // $link is http://example.com/path/to/frontcontroller/posts/show?id=7
 * </code>
 * 
 * Advanced:
 * <code>
 * require '/path/to/Simph/Router.php';
 * 
 * $router = new Simph_Router;
 * 
 * // You can rewrite URL defining a route
 * $router->route('/posts/:id', 'posts/show.php')
 *    // Optionally you can define a regexp for validate the :id parameter
 *    ->def('id', '\d+');
 * 
 * // You can define optional sub pattern with parenthesis
 * $router->route('/posts(/page-:page)', 'posts/index.php')
 *    // And define either the regexp for validate the :page parameter and its 
 *    // default value
 *    ->def('page', '\d+', 1);
 * 
 * // path that match the request
 * $path = $router->matchRequest();
 * 
 * // The absolute path to show the post with the id = 7
 * $link = $router->pathFor('posts/show.php', array('id' => 7));
 * // $link is now /http/path/to/frontcontroller/posts/7
 * 
 * // The absolute URL  to show the post with the id = 7
 * $link = $router->urlFor('posts/show.php', array('id' => 7));
 * // $link is now http://example.com/path/to/frontcontroller/posts/7
 * </code>
 * 
 * Parameters can be defined globally: 
 * <code>
 * $router
 *   ->def('id', '\d+')
 *   ->def('page', '\d', '1')
 * 
 * $router->route('/posts/:id', 'posts/show.php');
 * $router->route('/posts(/page-:page)', 'posts/index.php') * 
 * </code>
 * 
 */
class Simph_Router 
{    
    
    /**
     * The HTTP path to your web root. The value is auto generated on 
     * construction
     * @var string
     */
    public $web;
    
    /**
     * The last matched request
     * @var string
     */
    public $request;
    
    /**
     * The path that matched the request
     * @var string
     */
    public $pagePath;
    
    /**
     * The front controller file name. Leave it blank if you are using 
     * mod_rewrite to remove it on your URL.
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
     * @param string $frontFile  The front controller file name. Leave it blank 
     *                           if you are using mod_rewrite to remove it on 
     *                           your URL.
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
     * @param string $pattern     Pattern to match the request. For example 
     *                            /users/:id
     * @param string $pagePath    Page path to handle the request if pattern 
     *                            match the request
     * @return Simph_Router_Route 
     */
    function route($pattern, $pagePath) {
        $route = new Simph_Router_Route($pattern);
        
        foreach ($this->globalDefinitions as $name => $def) {
            $route->def($name, $def[0], $def[1]);
        }
        
        $this->routes[$pagePath] = $route;
        
        return $route;
                
    }
    
    /**
     * Determine the handler file path based on the request
     * 
     * @return string  The path to handle the request
     */    
    function matchRequest() {
        $request = explode('?', $_SERVER['REQUEST_URI']);
        $request = preg_replace('#' . $this->web . '(?:' . $this->frontFile 
                . '/)?(.*)#', "/$1", $request[0]);
        
        return $this->match($request);
        
    }
    
    /**
     * Determine the handler file path based on the given argument
     * 
     * @param string $request   The request to match
     * @return string           The path that match the request given as first 
     *                          argument
     *
     */
    function match($request) {
        
        $this->request = $request;
        
        // Check if the request match a route
        foreach ($this->routes as $path => $route) {
            if (false !== ($params = $route->match($request))) {
                // Update $_GET
                $_GET = $params + $_GET;
                return $this->pagePath = $path;
            }
        }
        
        // Unrouted request can't contain "/_" because file starting with _ are
        // private
        if (strpos($request, '/_') !== false) {
            throw new Simph_Router_HttpException('Bad Request', '400');
        }
        
        // Redirect request that end with index to have consistent URLs
        if (substr($request, -5) === 'index') {
            $this->redirect($this->urlFor($request . '.php', $_GET), '301');
 
        }

        // Add index to request that end with /
        if (substr($request, -1) === '/') {
            $request .= 'index';
        }
        
        // Add extension and remove the / as prefix
        $request .= '.php';
        $request = ltrim($request, '/');
        
        // The request match to a rewrited one 
        if (isset($this->routes[$request])) {
            // Redirect the request to the rewrited version
            try {
                $this->redirect($this->urlFor($request, $_GET), '301');

            } catch (Simph_Router_Exception $e) {
                throw new Simph_Router_HttpException('Not Found', '404');

            }

        } else {
            return $this->pagePath = $request;

        }

    }
    
    /**
     * Creates the absolute URL of a resource without hostname
     * 
     * @param string $path           The path of a resource that have to be 
     *                               transformed in a routed path
     * @param array|object $params   Parameters of the URL
     * @return string                The absolute HTTP URL of the resource 
     *                               without hostname
     */
    function pathFor($path, $params = array()) {
        
        // Shorthand for the home page
        if ($path === 'home') {

            $path = '/';

        } else {
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
     * Creates the absolute URL of a resource with the hostname
     * 
     * @param string $path           The path of a resource that have to be 
     *                               transformed in a routed path
     * @param array|object $params   Parameters of the URL
     * @param string $schema         Schema to use as prefix of the URL
     * @return string                The absolute HTTP URL of the resource
     */
    function urlFor($path, $params = array(), $schema = 'http://') {        
        $port = isset($_SERVER['HTTP_PORT'])? ':' . $_SERVER['HTTP_PORT']: '';
        return $schema . $_SERVER['HTTP_HOST'] . $port . $this->pathFor($path, $params);

    }

    /**
     * HTTP redirects
     *
     * @param string $url
     * @param string|int $status
     */
    function redirect($url, $status = '302') {
        header('Location: ' . $url, true, $status);
        die;

    }
    
    
}



/**
 * Simph_Router_Route is used to define a single route
 */
class Simph_Router_Route {

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
     * Array of regexps describing each parameter that need to override the 
     * default regexp definition
     * @var array 
     */
    protected $definitions = array();
    
    /**
     * Default regexp definition to match a parameter in the request
     * @var string
     */
    protected $defaultDefinition = '([\w\d_\-]+)';


    /**
     * Scalar array of paremeter placeholders
     * @var array
     */
    protected $variables = array();
    
    /**
     * Scalar array of optional paremeter sub patterns. 
     * For example "(page/:page)"
     * @var array
     */
    protected $optionals = array();
    
    /**
     * Route implement a lazy setup. This var is used to know if the object is 
     * already set up.
     * @var null|bool
     */
    protected $done;
    
    /**
     * The regular expression to match the request
     * @var string
     */
    protected $regexp;
    
    
    /**
     * @param string $pattern 
     */
    function __construct($pattern) {

        $this->pattern = $pattern;

    }
    
    /**
     * Override the default regexp parameter definition and/or set a 
     * default value of a optional parameter
     * 
     * @param string $param       The parameter name
     * @param string $definition  A regexp defining the parameter
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
         * Set up the regular expression to match the request
         */
        $this->setUp();

        if (preg_match("#^" . $this->regexp . "$#U", $request, $match)) {

            /*
             * shift the entire request from the first position
             */
            array_shift($match);
            for ($i = 0, $j = count($match); $i < $j; $i++) {
                if (isset($this->variables[$i])) {
                    $this->defaults[$this->variables[$i]] = $match[$i];

                }

            }

            return $this->defaults;

        }

        return false;

    }
    
    /**
     * Fill out the pattern with the given arguments and return the path to 
     * link the resource.
     * 
     * Placeholders in the pattern need to match the keys/properties of the 
     * array or object given as first argument. If the first argument is 
     * an object and no property match with a placeholder, the function check 
     * for a method getCamelizedVariableName. 
     * 
     * If a required variable is not given, the function thrown an exception.
     * 
     * @param array|stdClass $values   Parameters to use in the link
     * @return string                  Link path
     * @throws Simph_Router_Route_Exception
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

            } elseif (isset($this->optionals[$var])) {
                $str = $this->optionals[$var];
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
     * Set up the regexp to match the request, the definitions of each variable, 
     * the variable list itself and the list of optionals sub patterns
     */
    protected function setUp() {

        if (!isset($this->done)) {

            if (strpos($this->pattern, ':') !== false) {                
                
                $pattern = $this->pattern;

                if (preg_match_all('#\(.*:([\w\d_]+).*\)#', $pattern, $match)) {

                    for ($i = 0, $j = count($match[0]); $i < $j; ++$i) {

                        $pattern = str_replace($match[0][$i], '%s', $pattern);

                        // array('page' => '(page/:page)')
                        $this->optionals[$match[1][$i]] = $match[0][$i];

                    }

                }

                $regexp = preg_replace_callback('#:[\w\d_]+#', array($this, 'setRegexp'), str_replace('\\:', ':', preg_quote($pattern, '#')));
                
                if ($this->optionals) {
                    $regexp = str_replace(array('(', ')'), array('(?:', ')?'), vsprintf($regexp, $this->optionals));
                    $regexp = preg_replace_callback('#(?<!\?):[\w\d_]+#', array($this, 'setRegexp'), $regexp);
                }

                $this->regexp = $regexp;

            }

            $this->done = true;

        }

    }
    
    /**
     * Callback used in $this->setUp() as argument of preg_replace_callback
     * 
     * @param array $match Param placeholder in the form :param_name
     * @return string      Sub regexp to match the single parameter
     */
    protected function setRegexp($match) {

        $var = substr($match[0], 1);
        $this->variables[] = $var;
        if (isset($this->definitions[$var])) {
            return '(' . $this->definitions[$var] . ')';

        }

        return $this->defaultDefinition;


    }

}

class Simph_Router_Exception extends Exception
{

}

class Simph_Router_HttpException extends Simph_Router_Exception
{

}