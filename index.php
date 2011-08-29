<?php
require dirname(__FILE__) . '/lib/Simph/Router.php';
$router = new Simph_Router();

$router
    ->def('id', '\d+')
    ->def('page', '\d+', '1');

$router->route('/users/:id', 'users/show.php');
$router->route('/posts(/page-:page)', 'posts/index.php');


try {
    $page = dirname(__FILE__) . '/app/modules/' . $router->matchRequest();
    if (file_exists($page)) {
        require $page;

    } else {
        error_404();

    }

} catch (Simph_Router_HttpException $e) {
    error_404();
    
}

