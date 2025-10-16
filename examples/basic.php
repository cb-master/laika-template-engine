<?php


require __DIR__ . '/../vendor/autoload.php';


use Laika\Template\Template;


$tpl = new Template(__DIR__ . '/views');
$tpl->assign('name', 'Showket');
$tpl->assign('users', [['name' => 'Alice'], ['name' => 'Bob']]);


// Make sure views directory exists and has a file `home.tpl`
echo $tpl->render('home');