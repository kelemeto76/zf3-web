<?php

namespace AppTest\Action;

use App\Action\HomePageAction;
use App\Action\HomePageFactory;
use Interop\Container\ContainerInterface;
use Zend\Expressive\Template\TemplateRendererInterface;
use App\Model\Post;
use App\Model\Advisory;
use ArrayObject;

class HomePageFactoryTest extends \PHPUnit_Framework_TestCase
{
    /** @var ContainerInterface */
    protected $container;

    protected function setUp()
    {
        $this->container = $this->prophesize(ContainerInterface::class);

        $post = $this->prophesize(Post::class);
        $advisory = $this->prophesize(Advisory::class);

        $this->container->get(Post::class)->willReturn($post);
        $this->container->get(Advisory::class)->willReturn($advisory);
        $this->container->get('config')->willReturn(new ArrayObject([ 'zf_components' => [] ]));
    }

    public function testFactoryWithoutTemplate()
    {
        $factory = new HomePageFactory();
        $this->container->has(TemplateRendererInterface::class)->willReturn(false);

        $this->assertTrue($factory instanceof HomePageFactory);

        $homePage = $factory($this->container->reveal());

        $this->assertTrue($homePage instanceof HomePageAction);
    }

    public function testFactoryWithTemplate()
    {
        $factory = new HomePageFactory();
        $this->container->has(TemplateRendererInterface::class)->willReturn(true);
        $this->container
            ->get(TemplateRendererInterface::class)
            ->willReturn($this->prophesize(TemplateRendererInterface::class));

        $this->assertTrue($factory instanceof HomePageFactory);

        $homePage = $factory($this->container->reveal());

        $this->assertTrue($homePage instanceof HomePageAction);
    }
}
