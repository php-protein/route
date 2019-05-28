<?php declare(strict_types=1);

/**
 * Route
 *
 * URL Router and action dispatcher.
 *
 * @package Proteins
 * @author  "Stefano Azzolini"  <lastguest@gmail.com>
 */

namespace Proteins;

class Route {

    use Extensions,
        Events {
          on as onEvent;
        }

    public static $routes;
    public static $base           = '';
    public static $prefix         = [];
    public static $group          = [];
    public static $tags           = [];
    public static $optimized_tree = [];

    protected $URLPattern         = '';
    protected $pattern            = '';
    protected $matcher_pattern    = '';
    protected $dynamic            = false;
    protected $callback           = null;
    protected $methods            = [];
    protected $befores            = [];
    protected $afters             = [];
    protected $rules              = [];
    protected $response           = '';
    protected $tag                = '';


    /**
     * Create a new route definition. This method permits a fluid interface.
     *
     * @param string $URLPattern The URL pattern, can be used named parameters for variables extraction
     * @param callable $callback The callback to invoke on route match.
     * @param string $method The HTTP method for which the route must respond.
     * @return Route|RouteGroup
     */
    public function __construct($URLPattern, $callback = null, $method='get') {
        $prefix  = static::$prefix ? rtrim(implode('', static::$prefix), '/') : '';
        $pattern = '/' . trim($URLPattern, "/");

        $this->callback         = $callback;

        // Adjust / optionality with dynamic patterns
        // Ex:  /test/(:a) ===> /test(/:a)
        $this->URLPattern       = str_replace('//', '/', str_replace('/(', '(/', rtrim("{$prefix}{$pattern}", "/")));

        $this->dynamic          = $this->isDynamic($this->URLPattern);

        $this->pattern          = $this->dynamic
                                ? $this->compilePatternAsRegex($this->URLPattern, $this->rules)
                                : $this->URLPattern;

        $this->matcher_pattern  = $this->dynamic
                                ? $this->compilePatternAsRegex($this->URLPattern, $this->rules, false)
                                : '';

        // We will use hash-checks, for O(1) complexity vs O(n)
        $this->methods[$method] = 1;
        return static::add($this);
    }

    /**
     * Check if route match on a specified URL and HTTP Method.
     * @param  URL|string $URL The URL to check against.
     * @param  string $method The HTTP Method to check against.
     * @return boolean
     */
    public function match($URL, $method='get') {
        $method = strtolower($method);

        // * is an http method wildcard
        if (empty($this->methods[$method]) && empty($this->methods['*'])) {
            return false;
        }

        return (bool) (
          $this->dynamic
           ? preg_match($this->matcher_pattern, '/'.trim($URL, '/'))
           : rtrim($URL, '/') == rtrim($this->pattern, '/')
      );
    }

    /**
     * Clears all stored routes definitions to pristine conditions.
     * @return void
     */
    public static function reset() {
        static::$routes = [];
        static::$base   = '';
        static::$prefix = [];
        static::$group  = [];
        static::$optimized_tree = [];
    }

    /**
     * Run one of the mapped callbacks to a passed HTTP Method.
     *
     * @param array  $args The arguments to be passed to the callback
     * @param string $method The HTTP Method requested.
     *
     * @return (string|mixed)[] The callback response.
     *
     * @psalm-return array{0:string|mixed}
     */
    public function run(array $args, $method='get'): array {
        $method = strtolower($method);
        $append_echoed_text = Options::get('core.route.append_echoed_text', true);
        static::trigger('start', $this, $args, $method);

        // Call direct befores
        if ($this->befores) {
            // Reverse befores order
            foreach (array_reverse($this->befores) as $mw) {
                static::trigger('before', $this, $mw);
                ob_start();
                $mw_result  = call_user_func($mw);
                $raw_echoed = ob_get_clean();
                if ($append_echoed_text) {
                    Response::add($raw_echoed);
                }
                if (false  === $mw_result) {
                    return [''];
                } else {
                    Response::add($mw_result);
                }
            }
        }

        $callback = (is_array($this->callback) && isset($this->callback[$method]))
                  ? $this->callback[$method]
                  : $this->callback;

        if (is_callable($callback) || is_a($callback, "Core\\View")) {
            Response::type(Options::get('core.route.response_default_type', Response::TYPE_HTML));

            ob_start();
            if (is_a($callback, "Core\\View")) {
                // Get the rendered view
                $view_results = (string)$callback;
            } else {
                $view_results = call_user_func_array($callback, $args);
            }
            $raw_echoed   = ob_get_clean();

            if ($append_echoed_text) {
                Response::add($raw_echoed);
            }
            Response::add($view_results);
        }

        // Apply afters
        if ($this->afters) {
            foreach ($this->afters as $mw) {
                static::trigger('after', $this, $mw);
                ob_start();
                $mw_result  = call_user_func($mw);
                $raw_echoed = ob_get_clean();
                if ($append_echoed_text) {
                    Response::add($raw_echoed);
                }
                if (false  === $mw_result) {
                    return [''];
                } else {
                    Response::add($mw_result);
                }
            }
        }

        static::trigger('end', $this, $args, $method);

        return [Filter::with('core.route.response', Response::body())];
    }

    /**
     * Check if route match URL and HTTP Method and run if it is valid.
     *
     * @param [type] $URL The URL to check against.
     * @param string $method The HTTP Method to check against.
     *
     * @return (string|mixed)[]|null The callback response.
     *
     * @psalm-return array{0:string|mixed}|null
     */
    public function runIfMatch($URL, $method='get') {
        return $this->match($URL, $method) ? $this->run($this->extractArgs($URL), $method) : null;
    }

    /**
     * Start a route definition, default to HTTP GET.
     * @param  string $URLPattern The URL to match against, you can define named segments to be extracted and passed to the callback.
     * @param  $callback The callback to be invoked (with variables extracted from the route if present) when the route match the request URI.
     * @return Route
     */
    public static function on($URLPattern, $callback = null) {
        return new Route($URLPattern, $callback);
    }

    /**
     * Start a route definition with HTTP Method via GET.
     * @param  string $URLPattern The URL to match against, you can define named segments to be extracted and passed to the callback.
     * @param  $callback The callback to be invoked (with variables extracted from the route if present) when the route match the request URI.
     * @return Route
     */
    public static function get($URLPattern, $callback = null)
    {
        return (new Route($URLPattern, $callback))->via('get');
    }

    /**
     * Start a route definition with HTTP Method via POST.
     * @param  string $URLPattern The URL to match against, you can define named segments to be extracted and passed to the callback.
     * @param  $callback The callback to be invoked (with variables extracted from the route if present) when the route match the request URI.
     * @return Route
     */
    public static function post($URLPattern, $callback = null) {
        return (new Route($URLPattern, $callback))->via('post');
    }

    /**
     * Start a route definition, for any HTTP Method (using * wildcard).
     * @param  string $URLPattern The URL to match against, you can define named segments to be extracted and passed to the callback.
     * @param  $callback The callback to be invoked (with variables extracted from the route if present) when the route match the request URI.
     * @return Route
     */
    public static function any($URLPattern, $callback = null) {
        return (new Route($URLPattern, $callback))->via('*');
    }

    /**
     * Bind a callback to the route definition
     * @param  $callback The callback to be invoked (with variables extracted from the route if present) when the route match the request URI.
     * @return Route
     */
    public function & with($callback) {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Bind a middleware callback to invoked before the route definition
     * @param  callable $before The callback to be invoked ($this is binded to the route object).
     * @return Route
     */
    public function & before($callback) {
        $this->befores[] = $callback;
        return $this;
    }

    /**
     * Bind a middleware callback to invoked after the route definition
     * @param  $callback The callback to be invoked ($this is binded to the route object).
     * @return Route
     */
    public function & after($callback) {
        $this->afters[] = $callback;
        return $this;
    }

    /**
     * Defines the HTTP Methods to bind the route onto.
     *
     * Example:
     * <code>
     *  Route::on('/test')->via('get','post','delete');
     * </code>
     *
     * @return Route
     */
    public function & via(...$methods) {
        $this->methods = [];
        foreach ($methods as $method) {
            $this->methods[strtolower($method)] = true;
        }
        return $this;
    }

    /**
     * Defines the regex rules for the named parameter in the current URL pattern
     *
     * Example:
     * <code>
     *  Route::on('/proxy/:number/:url')
     *    ->rules([
     *      'number'  => '\d+',
     *      'url'     => '.+',
     *    ]);
     * </code>
     *
     * @param  mixed  $rules The regex rules
     * @return Route
     */
    public function & rules(array $rules) {
        foreach ((array)$rules as $varname => $rule) {
            $this->rules[$varname] = $rule;
        }
        $this->pattern         = $this->compilePatternAsRegex($this->URLPattern, $this->rules);
        $this->matcher_pattern = $this->compilePatternAsRegex($this->URLPattern, $this->rules, false);
        return $this;
    }

    /**
     * Map a HTTP Method => callable array to a route.
     *
     * Example:
     * <code>
     *  Route::map('/test'[
     *      'get'     => function(){ echo "HTTP GET"; },
     *      'post'    => function(){ echo "HTTP POST"; },
     *      'put'     => function(){ echo "HTTP PUT"; },
     *      'delete'  => function(){ echo "HTTP DELETE"; },
     *    ]);
     * </code>
     *
     * @param  string $URLPattern The URL to match against, you can define named segments to be extracted and passed to the callback.
     * @param  array $callbacks The HTTP Method => callable map.
     * @return Route
     */
    public static function & map($URLPattern, $callbacks = []) {
        $route           = new static($URLPattern);
        $route->callback = [];
        foreach ($callbacks as $method => $callback) {
            $method = strtolower($method);
            if (Request::method() !== $method) {
                continue;
            }
            $route->callback[$method] = $callback;
            $route->methods[$method]  = 1;
        }
        return $route;
    }

    /**
     * Assign a name tag to the route
     * @param  string $name The name tag of the route.
     * @return Route
     */
    public function & tag($name) {
        if ($this->tag = $name) {
            static::$tags[$name] =& $this;
        }
        return $this;
    }

    /**
     * Reverse routing : obtain a complete URL for a named route with passed parameters
     * @param  mixed $params The parameter map of the route dynamic values.
     * @return URL
     */
    public function getURL($params = []) {
        $params = (array)$params;
        return new URL(rtrim(preg_replace('(/+)', '/', preg_replace_callback('(:(\w+))', function ($m) use ($params): string {
            return isset($params[$m[1]]) ? $params[$m[1]].'/' : '';
        }, strtr($this->URLPattern, ['('=>'',')'=>'']))), '/')?:'/');
    }

    /**
     * Get a named route
     * @param  string $name The name tag of the route.
     * @return Route or false if not found
     */
    public static function tagged($name) {
        return isset(static::$tags[$name]) ? static::$tags[$name] : false;
    }

    /**
      * Helper for reverse routing : obtain a complete URL for a named route with passed parameters
      *
      * @param string $name The name tag of the route.
      * @param array $params The parameter map of the route dynamic values.
      *
      * @return URL
      */
    public static function URL($name, $params = []) {
        return ($r = static::tagged($name)) ? $r-> getURL($params) : new URL();
    }

    /**
     * Compile an URL schema to a PREG regular expression.
     * @param  string $pattern The URL schema.
     * @return string The compiled PREG RegEx.
     */
    protected static function compilePatternAsRegex($pattern, $rules=[], $extract_params=true) {
        return '#^'.preg_replace_callback(
          '#:([a-zA-Z]\w*)#',
          $extract_params
        // Extract named parameters
        ? function ($g) use (&$rules): string {
            return '(?<' . $g[1] . '>' . (isset($rules[$g[1]])?$rules[$g[1]]:'[^/]+') .')';
        }
        // Optimized for matching
        : function ($g) use (&$rules) {
            return isset($rules[$g[1]]) ? $rules[$g[1]] : '[^/]+';
        },
          str_replace(['.',')','*'], ['\.',')?','.+'], $pattern)
      ).'$#';
    }

    /**
     * Extract the URL schema variables from the passed URL.
     * @param  string  $pattern The URL schema with the named parameters
     * @param  string  $URL The URL to process, if omitted the current request URI will be used.
     * @param  boolean $cut If true don't limit the matching to the whole URL (used for group pattern extraction)
     * @return mixed The extracted variables of false on unmatch
     */
    protected static function extractVariablesFromURL($pattern, $URL=null, $cut=false) {
        $URL     = $URL ?: Request::URI();
        $pattern = $cut ? str_replace('$#', '', $pattern).'#' : $pattern;
        $args    = [];
        if (!preg_match($pattern, '/'.trim($URL, '/'), $args)) {
            return false;
        }
        foreach ($args as $key => $value) {
            if (false === is_string($key)) {
                unset($args[$key]);
            }
        }
        return $args;
    }


    /**
     * @return string[]
     *
     * @psalm-return array<array-key, string>
     */
    public function extractArgs($URL): array {
        $args = [];
        if ($this->dynamic) {
            preg_match($this->pattern, '/'.trim($URL, '/'), $args);
            foreach ($args as $key => $value) {
                if (false === is_string($key)) {
                    unset($args[$key]);
                }
            }
        }
        return $args;
    }

    /**
     * Check if an URL schema need dynamic matching (regex).
     * @param  string  $pattern The URL schema.
     * @return boolean
     */
    protected static function isDynamic($pattern) {
        return strlen($pattern) != strcspn($pattern, ':(?[*+');
    }

    /**
     * Add a route to the internal route repository.
     *
     * @param  Route|RouteGroup $route
     * @return Route|\Core\RouteGroup
     */
    public static function add($route) {
        if (is_a($route, 'Core\\Route')) {

        // Add to tag map
            if ($route->tag) {
                static::$tags[$route->tag] =& $route;
            }

            // Optimize tree
            if (Options::get('core.route.auto_optimize', true)) {
                $base =& static::$optimized_tree;
                foreach (explode('/', trim(preg_replace('#^(.+?)\(?:.+$#', '$1', $route->URLPattern), '/')) as $segment) {
                    $segment = trim($segment, '(');
                    if (!isset($base[$segment])) {
                        $base[$segment] = [];
                    }
                    $base =& $base[$segment];
                }
                $base[] =& $route;
            }
        }

        // Add route to active group
        if (isset(static::$group[0])) {
            static::$group[0]->add($route);
        }

        return static::$routes[implode('', static::$prefix)][] = $route;
    }

    /**
     * Define a route group, if not immediately matched internal code will not be invoked.
     *
     * @param string $prefix The url prefix for the internal route definitions.
     * @param string $callback This callback is invoked on $prefix match of the current request URI.
     *
     * @return RouteGroup
     */
    public static function group($prefix, $callback) {

      // Skip definition if current request doesn't match group.
        $pre_prefix = rtrim(implode('', static::$prefix), '/');
        $URI   = Request::URI();
        $args  = [];
        $group = false;

        switch (true) {

        // Dynamic group
        case static::isDynamic($prefix):
          $args = static::extractVariablesFromURL($prx=static::compilePatternAsRegex("$pre_prefix$prefix"), null, true);
          if ($args !== false) {
              // Burn-in $prefix as static string
              $partial = preg_match_all(str_replace('$#', '#', $prx), $URI, $partial) ? $partial[0][0] : '';
              $prefix = $partial ? preg_replace('#^'.implode('', static::$prefix).'#', '', $partial) : $prefix;
          }

        // Static group
        // no break
        case (0 === strpos("$URI/", "$pre_prefix$prefix/"))
             || (! Options::get('core.route.pruning', true)):

          static::$prefix[] = $prefix;
          if (empty(static::$group)) {
              static::$group = [];
          }
          array_unshift(static::$group, $group = new RouteGroup());

          // Call the group body function
          call_user_func_array($callback, $args ?: []);

          array_shift(static::$group);
          array_pop(static::$prefix);
          if (empty(static::$prefix)) {
              static::$prefix = [''];
          }
        break;

      }

        return $group ?: new RouteGroup();
    }

    /**
     * @return void
     */
    public static function exitWithError($code, $message="Application Error") {
        Response::error($code, $message);
        Response::send();
        exit;
    }

    /**
     * Start the route dispatcher and resolve the URL request.
     * @param  string $URL The URL to match onto.
     * @param  string $method The HTTP method.
     * @param  bool $return_route If setted to true it will *NOT* execute the route but it will return her.
     * @return boolean true if a route callback was executed.
     */
    public static function dispatch($URL=null, $method=null, $return_route=false) {
        if (!$URL) {
            $URL     = Request::URI();
        }
        if (!$method) {
            $method  = Request::method();
        }

        $__deferred_send = new Deferred(function () {
            if (Options::get('core.response.autosend', true)) {
                Response::send();
            }
        });

        if (empty(static::$optimized_tree)) {
            foreach ((array)static::$routes as $group => $routes) {
                foreach ($routes as $route) {
                    if (is_a($route, __CLASS__) && $route->match($URL, $method)) {
                        if ($return_route) {
                            return $route;
                        } else {
                            $route->run($route->extractArgs($URL), $method);
                            return true;
                        }
                    }
                }
            }
        } else {
            $routes =& static::$optimized_tree;
            foreach (explode('/', trim($URL, '/')) as $segment) {
                if (is_array($routes) && isset($routes[$segment])) {
                    $routes =& $routes[$segment];
                }
                // Root-level dynamic routes Ex: "/:param"
                elseif (is_array($routes) && isset($routes[''])) {
                    $routes =& $routes[''];
                } else {
                    break;
                }
            }
            if (is_array($routes) && isset($routes[0]) && !is_array($routes[0])) {
                foreach ($routes as $route) {
                    if (is_a($route, __CLASS__) && $route->match($URL, $method)) {
                        if ($return_route) {
                            return $route;
                        } else {
                            $route->run($route->extractArgs($URL), $method);
                            return true;
                        }
                    }
                }
            }
        }

        Response::status(404, '404 Resource not found.');
        foreach (array_filter((static::trigger(404)?:[])) as $res) {
            Response::add($res);
        }
        return false;
    }

    /**
     * @return Route
     */
    public function push($links, $type = 'text') {
        Response::push($links, $type);
        return $this;
    }
}

// RouteGroup is a private class of Route
class RouteGroup {
    protected $routes;

    public function __construct()
    {
        $this->routes = new \SplObjectStorage;
        return Route::add($this);
    }

    public function has($r)
    {
        return $this->routes->contains($r);
    }

    /**
     * @return RouteGroup
     */
    public function add($r)
    {
        $this->routes->attach($r);
        return $this;
    }

    /**
     * @return RouteGroup
     */
    public function remove($r)
    {
        if ($this->routes->contains($r)) {
            $this->routes->detach($r);
        }
        return $this;
    }

    /**
     * @return RouteGroup
     */
    public function before($callbacks)
    {
        foreach ($this->routes as $route) {
            $route->before($callbacks);
        }
        return $this;
    }

    /**
     * @return RouteGroup
     */
    public function after($callbacks)
    {
        foreach ($this->routes as $route) {
            $route->after($callbacks);
        }
        return $this;
    }

    /**
     * @return RouteGroup
     */
    public function push($links, $type = 'text')
    {
        Response::push($links, $type);
        return $this;
    }
}
