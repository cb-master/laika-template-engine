---


## tests/UnitTest.php (very basic)


```php
<?php


use PHPUnit\Framework\TestCase;
use Laika\Template\Template;


class UnitTest extends TestCase
{
    public function testRenderSimple()
    {
        $path = __DIR__ . '/../examples/views';
        $tpl = new Template($path);

        $tpl->assign('name', 'Showket');
        $tpl->assign('users', [['name' => 'Alice'], ['name' => 'Bob']]);

        $out = $tpl->render('home');
        $this->assertStringContainsString('UnitTest', $out);
    }
}
