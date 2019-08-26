<?php

namespace App\Action;

use DOMNode;
use DOMXPath;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Dom\Query as DomQuery;
use Zend\Expressive\Template;

class ManualAction implements RequestHandlerInterface
{
    /** @var array */
    private $config;

    /** @var Template\TemplateRendererInterface */
    private $template;

    public function __construct(array $config, Template\TemplateRendererInterface $template)
    {
        $this->config   = $config;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $page    = $request->getAttribute('page', false);
        $version = $request->getAttribute('version', false);
        $lang    = $request->getAttribute('lang', false);
        $subpage = $request->getAttribute('subpage', false);

        $page    = $subpage ? $page . '/' . $subpage : $page;

        // Check URL params
        if (! $page || false === $version || ! $lang) {
            $this->log(sprintf(
                'Missing page ("%s"), version ("%s"), or language ("%s")',
                $page ?: '',
                $version ?: '',
                $lang ?: ''
            ));
            return new HtmlResponse($this->template->render('error::404'), 404);
        }

        $name = $page;

        // Create doc filename

        $docFile = $this->config['zf_document_path'][$version][$lang] . $page;

        // Check file
        if (! file_exists($docFile)) {
            $this->log(sprintf('Identified documentation file "%s" for page "%s" could not be found', $docFile, $page));
            return new HtmlResponse($this->template->render('error::404'), 404);
        }

        // Get page content
        $content = $this->getPageContent($docFile, $version);

        // Check content
        if (false === $content) {
            $this->log(sprintf(
                'No page content could be retrieved for docfile "%s" associated with page "%s"',
                $docFile,
                $page
            ));
            return new HtmlResponse($this->template->render('error::404'), 404);
        }

        // Get content list for select element
        $contentList = $this->getContentList(
            $this->config['zf_document_path'][$version][$lang],
            $version
        );

        // Get current page link for select element with content list
        $getLinks = function (array $pages) use (&$getLinks) {
            $links = [];
            foreach ($pages as $key => $value) {
                $links[] = $key;
                if (is_array($value)) {
                    $links = array_merge($links, $getLinks($value));
                }
            }
            return $links;
        };

        if (in_array($page, $getLinks($contentList), true)) {
            $currentPage = $page;
        } else {
            $currentPage = $this->getSelectedPage(
                $page,
                $this->config['zf_document_path'][$version][$lang],
                $version
            );
        }

        // Get current page title
        $currentPageTitle = $this->getSelectedPage(
            $page,
            $this->config['zf_document_path'][$version][$lang],
            $version,
            false
        );

        $currentPageV3ComponentUrl = null;

        // Usually the page title is the namespace of the component,
        // which can be used to figure out the URL from the component-url map.
        if ($currentPageTitle !== null && strpos($currentPageTitle, '\\') !== false) {
            $componentName = str_replace(
                '\\',
                '-',
                strtolower($currentPageTitle)
            );

            $currentPageV3ComponentUrl = $this->config['zf_component_url_map'][$componentName] ?? null;
        }

        // Set variables for the template
        $data = [
            'name'                      => $name,
            'lang'                      => $lang,
            'page'                      => $page,
            'title'                     => $content['title'],
            'body'                      => $content['body'],
            'sidebar'                   => $content['sidebar'],
            'version'                   => $version,
            'latestVersion'             => $this->config['zf_latest_version'],
            'latestZf1Version'          => $this->config['zf1_latest_version'],
            'contentList'               => $contentList,
            'currentPage'               => $currentPage,
            'currentPageTitle'          => $currentPageTitle,
            'currentPageV3ComponentUrl' => $currentPageV3ComponentUrl,
        ];

        // Sort version numbers
        $versions = array_keys($this->config['zf_document_path']);
        rsort($versions, SORT_NATURAL);
        $data['versions'] = $versions;

        return new HtmlResponse($this->template->render('app::manual', $data));
    }

    /**
     * Get page content (body, sidebar) according to the doc version
     *
     * @param  string $file
     * @param  string $version
     * @return bool|array
     */
    protected function getPageContent(string $file, string $version)
    {
        if (strpos($version, '1.1') === 0) {
            return $this->getV1PageContent($file);
        }
        if (strpos($version, '1.') === 0) {
            return $this->getOldV1PageContent($file);
        }
        return $this->getV2PageContent($file);
    }

    /**
     * Get page content for version < 1.11
     *
     * @param  string $file
     * @return array
     */
    protected function getOldV1PageContent(string $file)
    {
        $pageContent = [];

        $doc                    = new DomQuery(file_get_contents($file));
        $pageContent['body']    = '';
        $pageContent['sidebar'] = '';
        $pageContent['title']   = '';

        $sidebar = true;
        // Body
        $content = $doc->queryXpath('//div[@class="book"]');
        if (count($content)) {
            $pageContent['body'] = $content->current()->ownerDocument->saveXML(
                $content->current()
            );
            $sidebar = false;
        }

        $content = $doc->queryXpath('//div[@class="chapter"]/div[@class="sect1"]');
        if (count($content)) {
            $xpath = new DOMXPath($content->getDocument());

            // Replace A link tag without text with a space
            $nodelist = $xpath->query(
                '//a[@name]'
            );

            foreach ($nodelist as $node) {
                $newElement = $content->getDocument()->createElement(
                    'a',
                    ' '
                );
                $newElement->setAttribute('name', $node->getAttribute('name'));
                $node->parentNode->replaceChild($newElement, $node);
            }

            $pageContent['body'] = $content->current()->ownerDocument->saveXML(
                $content->current()
            );
        }

        if (empty($pageContent['body'])) {
            $content = $doc->queryXpath('//div[@class="sect1"]');
            if (count($content)) {
                $xpath = new DOMXPath($content->getDocument());
                // Replace A link tag without text with a space
                $nodelist = $xpath->query(
                    '//a[@name]'
                );

                foreach ($nodelist as $node) {
                    $newElement = $content->getDocument()->createElement(
                        'a',
                        ' '
                    );
                    $node->parentNode->replaceChild($newElement, $node);
                }
                $pageContent['body'] = $content->current()->ownerDocument->saveXML(
                    $content->current()
                );
            }
        }

        // Sidebar
        $headline = $doc->queryXpath('//div[@class="toc"]');
        if ($sidebar && count($headline)) {
            $pageContent['sidebar'] = $headline->current()->ownerDocument->saveXML(
                $headline->current()
            );
        }

        // Previous topic
        $prevTopic = $doc->queryXpath('//div[@class="navheader"]//a[@accesskey="p"]')->current();
        if ($prevTopic instanceof DOMNode) {
            $pageContent['sidebar'] .= '<h1>Previous topic</h1>';
            $pageContent['sidebar'] .= sprintf(
                '<p class="topless"><a href="%s" title="previous chapter">%s</a></p>',
                $prevTopic->getAttribute('href'),
                $prevTopic->nodeValue
            );
        }

        // Next topic
        $nextTopic = $doc->queryXpath('//div[@class="navheader"]//a[@accesskey="n"]')->current();
        if ($nextTopic instanceof DOMNode) {
            $pageContent['sidebar'] .= '<h1>Next topic</h1>';
            $pageContent['sidebar'] .= sprintf(
                '<p class="topless"><a href="%s" title="next chapter">%s</a></p>',
                $nextTopic->getAttribute('href'),
                $nextTopic->nodeValue
            );
        }

        // Head title
        $elem = $doc->queryXpath('//ul[@class="toc"]/li[@class = "active"]/a/text()')->current();
        if ($elem instanceof DOMNode) {
            $pageContent['title'] = $elem->ownerDocument->saveXML($elem);
        }

        $elem = $doc->queryXpath('//ul[@class="toc"]/li[@class="header up"][last()]/a/text()')->current();
        if ($elem instanceof DOMNode) {
            $pageContent['title'] .= ' - ' . $elem->ownerDocument->saveXML($elem);
        }

        // Navigation
        $navigation = '';

        // Previous link
        $prevLink = $doc->queryXpath('//div[@class="navfooter"]//a[@accesskey="p"]')->current();
        if ($prevLink instanceof DOMNode) {
            $navigation .= sprintf(
                '<li class="prev"><a href="%s">%s</a>',
                $prevLink->getAttribute('href'),
                $prevLink->nodeValue
            );
        }

        // Next link
        $nextLink = $doc->queryXpath('//div[@class="navfooter"]//a[@accesskey="n"]')->current();
        if ($nextLink instanceof DOMNode) {
            $navigation .= sprintf(
                '<li class="next"><a href="%s">%s</a>',
                $nextLink->getAttribute('href'),
                $nextLink->nodeValue
            );
        }

        if (! empty($navigation)) {
            $navigation = sprintf(
                '<div class="related hide-on-print"><ul>%s</ul></div>',
                $navigation
            );
            $pageContent['body'] = $navigation . $pageContent['body'] . $navigation;
        }

        return $pageContent;
    }

    /**
     * Get page content from a v1.11+
     *
     * @param  string $file
     * @return array
     */
    protected function getV1PageContent(string $file)
    {
        $pageContent            = [];
        $doc                    = new DomQuery(file_get_contents($file));
        $pageContent['body']    = '';
        $pageContent['sidebar'] = '';
        $pageContent['title']   = '';

        // Body (standard)
        $content = $doc->queryXpath('//div[@class="section"]');
        if (count($content)) {
            $xpath = new DOMXPath($content->getDocument());

            // Replace headlines (h1 => h4)
            $nodelist = $xpath->query(
                '//div/div[@class = "section"]/div[@class = "section"]/div[@class = "section"]/div/h1[@class = "title"]'
            );

            foreach ($nodelist as $node) {
                $newElement = $content->getDocument()->createElement(
                    'h4',
                    $node->nodeValue
                );
                $node->parentNode->replaceChild($newElement, $node);
            }

            // Replace headlines (h1 => h3)
            $nodelist = $xpath->query(
                '//div/div[@class = "section"]/div[@class = "section"]/div/h1[@class = "title"]'
            );

            foreach ($nodelist as $node) {
                $newElement = $content->getDocument()->createElement(
                    'h3',
                    $node->nodeValue
                );
                $node->parentNode->replaceChild($newElement, $node);
            }

            // Replace headlines (h1 => h2)
            $nodelist = $xpath->query(
                '//div/div[@class = "section"]/div/h1[@class = "title"]'
            );

            foreach ($nodelist as $node) {
                $newElement = $content->getDocument()->createElement(
                    'h2',
                    $node->nodeValue
                );
                $node->parentNode->replaceChild($newElement, $node);
            }

            $pageContent['body'] = $content->current()->ownerDocument->saveXML(
                $content->current()
            );
        }

        // Body (table of contents)
        $headline    = $doc->queryXpath('//div[@id="the.index"]/strong/text()')->current();
        $contentList = $doc->queryXpath('//div[@id="the.index"]/ul')->current();
        if ($headline instanceof DOMNode && $contentList instanceof DOMNode) {
            $pageContent['body'] = '<h1>'
                . $headline->ownerDocument->saveXML($headline)
                . '</h1>'
                . $contentList->ownerDocument->saveXML($contentList);
        }

        // Body (part)
        $part = $doc->queryXpath('//div[@class="part"]')->current();
        if ($part instanceof DOMNode) {
            $h1          = $doc->queryXpath('//div[@class="part"]/h1')->current();
            $h2          = $doc->queryXpath('//div[@class="part"]/strong/text()')->current();
            $contentList = $doc->queryXpath('//div[@class="part"]/ul')->current();

            if ($h1 instanceof DOMNode && $h2 instanceof DOMNode && $contentList instanceof DOMNode) {
                $pageContent['body'] = $h1->ownerDocument->saveXML($h1)
                    . '<h2>'
                    . $h2->ownerDocument->saveXML($h2)
                    . '</h2>'
                    . $contentList->ownerDocument->saveXML($contentList);
            }
        }
        // Body (chapter and appendix)
        $body = $doc->queryXpath('//div[@class="chapter" or @class="appendix"]')->current();
        if ($body instanceof DOMNode) {
            $h1          = $doc->queryXpath('//div[@class="chapter" or @class="appendix"]//h1')->current();
            $h2          = $doc->queryXpath('//div[@class="chapter" or @class="appendix"]//strong/text()')->current();
            $contentList = $doc->queryXpath('//div[@class="chapter" or @class="appendix"]//ul')->current();

            if ($h1 instanceof DOMNode && $h2 instanceof DOMNode && $contentList instanceof DOMNode) {
                $pageContent['body'] = $h1->ownerDocument->saveXML($h1)
                    . '<h2>'
                    . $h2->ownerDocument->saveXML($h2)
                    . '</h2>'
                    . $contentList->ownerDocument->saveXML($contentList);
            } else {
                $pageContent['body'] = $body->ownerDocument->saveXML($body);
            }
        }

        // Sidebar

        // Headline (table of contents)
        $headline = $doc->queryXpath('//ul[@class="toc"]/li[@class = "header home"]/a');
        if ($headline instanceof DOMNode) {
            $pageContent['sidebar'] = sprintf(
                '<h1><a href="%s">Table Of Contents</a></h1>',
                $headline->current()->getAttribute('href')
            );
        }

        $pageContent['sidebar'] .= "<ul>\n";

        // First list item (section)
        $firstItem = $doc->queryXpath('//ul[@class="toc"]/li[@class = "header up"][last()]')->current();
        if ($firstItem instanceof DOMNode) {
            $pageContent['sidebar'] .= $firstItem->ownerDocument->saveXML($firstItem);
        }

        // Content list items
        $elements = $doc->queryXpath('//body/table/tr/td/div/div[@class="section" and @name and @id]');
        if ($elements->count() > 0) {
            // Active page
            $active = $doc->queryXpath('//ul[@class="toc"]/li[@class = "active"]/a')->current();

            $xpath = new DOMXPath($elements->getDocument());

            // Content list
            $pageContent['sidebar'] .= "<ul>\n";
            foreach ($elements as $element) {
                $pageContent['sidebar'] .= sprintf(
                    '<li><a href="%s">%s</a>',
                    $active->getAttribute('href') . '#' . $element->getAttribute('id'),
                    $element->childNodes->item(0)->nodeValue
                );

                // Sub elements
                $nodelist = $xpath->query(
                    'div[@class="section" and @name and @id]',
                    $element
                );

                if ($nodelist->length) {
                    $pageContent['sidebar'] .= '<ul>';

                    foreach ($nodelist as $node) {
                        $pageContent['sidebar'] .= sprintf(
                            '<li><a href="%s">%s</a></li>',
                            $active->getAttribute('href') . '#' . $node->getAttribute('id'),
                            $node->childNodes->item(0)->nodeValue
                        );
                    }

                    $pageContent['sidebar'] .= '</ul>';
                }

                $pageContent['sidebar'] .= '</li>';
            }
            $pageContent['sidebar'] .= "</ul>\n";
        }
        $pageContent['sidebar'] .= "</ul>\n";

        // Previous topic
        $prevTopic = $doc->queryXpath('//div[@class="next"]/parent::td/preceding-sibling::td/a')->current();

        if ($prevTopic instanceof DOMNode) {
            $pageContent['sidebar'] .= '<h1>Previous topic</h1>';
            $pageContent['sidebar'] .= sprintf(
                '<p class="topless"><a href="%s" title="previous chapter">%s</a></p>',
                $prevTopic->getAttribute('href'),
                $prevTopic->nodeValue
            );
        }

        // Next topic
        $nextTopic = $doc->queryXpath('//div[@class="next"]/a')->current();

        if ($nextTopic instanceof DOMNode) {
            $pageContent['sidebar'] .= '<h1>Next topic</h1>';
            $pageContent['sidebar'] .= sprintf(
                '<p class="topless"><a href="%s" title="next chapter">%s</a></p>',
                $nextTopic->getAttribute('href'),
                $nextTopic->nodeValue
            );
        }

        // Head title
        $elem = $doc->queryXpath('//ul[@class="toc"]/li[@class = "active"]/a/text()')->current();
        if ($elem instanceof DOMNode) {
            $pageContent['title'] = $elem->ownerDocument->saveXML($elem);
        }

        $elem = $doc->queryXpath('//ul[@class="toc"]/li[@class="header up"][last()]/a/text()')->current();
        if ($elem instanceof DOMNode) {
            $pageContent['title'] .= ' - ' . $elem->ownerDocument->saveXML($elem);
        }

        // Navigation
        $navigation = '';

        // Previous link
        $prevLink = $doc->queryXpath('//div[@class="next"]/parent::td/preceding-sibling::td/a')->current();
        if ($prevLink instanceof DOMNode) {
            $navigation .= sprintf(
                '<li class="prev"><a href="%s">%s</a>',
                $prevLink->getAttribute('href'),
                $prevLink->nodeValue
            );
        }

        // Next link
        $nextLink = $doc->queryXpath('//div[@class="next"]/a')->current();
        if ($nextLink instanceof DOMNode) {
            $navigation .= sprintf(
                '<li class="next"><a href="%s">%s</a>',
                $nextLink->getAttribute('href'),
                $nextLink->nodeValue
            );
        }

        if (! empty($navigation)) {
            $navigation = sprintf(
                '<div class="related hide-on-print"><ul>%s</ul></div>',
                $navigation
            );
            $pageContent['body'] = $navigation . $pageContent['body'] . $navigation;
        }

        return $pageContent;
    }

    /**
     * Get page content from a v2 manual
     *
     * @param  string $file
     * @return array
     */
    protected function getV2PageContent(string $file)
    {
        $pageContent = [];
        $doc         = new DomQuery(file_get_contents($file));

        // body
        $elem = $doc->queryXpath('//div[@class="body"]')->current();
        $pageContent['body'] = $elem->ownerDocument->saveXML($elem);
        $pageContent['body'] = preg_replace(
            '/(\.\.\/)*(_static|_images)/i',
            '/images/manual',
            $pageContent['body']
        );
        $pageContent['body'] = preg_replace(
            '/width: [6-9][0-9]{2}/i',
            'width: 650',
            $pageContent['body']
        );

        // Navigation
        $navigation = '';

        // Previous link
        $prevLink = $doc->queryXpath('//link[@rel="prev"]')->current();
        if ($prevLink) {
            $navigation .= sprintf(
                '<li class="prev"><a href="%s">%s</a>',
                $prevLink->getAttribute('href'),
                $prevLink->getAttribute('title')
            );
        }

        // Next link
        $nextLink = $doc->queryXpath('//link[@rel="next"]')->current();
        if ($nextLink) {
            $navigation .= sprintf(
                '<li class="next"><a href="%s">%s</a>',
                $nextLink->getAttribute('href'),
                $nextLink->getAttribute('title')
            );
        }

        if (! empty($navigation)) {
            $navigation = sprintf(
                '<div class="related hide-on-print"><ul>%s</ul></div>',
                $navigation
            );
            $pageContent['body'] = $navigation . $pageContent['body'] . $navigation;
        }

        // Sidebar
        $elements = $doc->queryXpath('//div[@class="sphinxsidebarwrapper"]/*');
        $pageContent['sidebar'] = '';

        /** @var \DOMNode $node */
        foreach ($elements as $node) {
            // Get TOC headline
            if ($node->nodeValue === 'Table Of Contents') {
                // Add headline to sidebar
                $pageContent['sidebar'] .= '<section id="toc">';
                $pageContent['sidebar'] .= $node->ownerDocument->saveXML($node);

                // Get TOC list
                if ('ul' === $node->nextSibling->nextSibling->nodeName) {
                    // Add list to sidebar
                    $pageContent['sidebar'] .= $node->ownerDocument->saveXML(
                        $node->nextSibling->nextSibling
                    );
                }
                // Add closing tag to sidebar
                $pageContent['sidebar'] .= '</section>';
            }

            // Get "This Page" headline
            // if ($node->nodeValue == 'This Page') {
            //     // Add headline to sidebar
            //     $pageContent['sidebar'] .= '<section id="this-page-menu">';
            //     $pageContent['sidebar'] .= $node->ownerDocument->saveXML($node);
            //
            //     // Get "This Page" menu
            //     $menu = $node->nextSibling->nextSibling;
            //     if ($menu && $menu->hasAttribute('class')
            //         && 'this-page-menu' == $menu->getAttribute('class')
            //     ) {
            //         // Add menu to sidebar
            //         $pageContent['sidebar'] .= $node->ownerDocument->saveXML(
            //             $menu
            //         );
            //     }
            //
            //     // Get note
            //     if ($menu
            //         && false !== strpos(
            //             trim($menu->nextSibling->nodeValue),
            //             'Note:'
            //         )
            //     ) {
            //         // Get content with its descendants ("<p><a></a></p>")
            //         $innerHTML = '';
            //         foreach ($menu->nextSibling->childNodes as $child) {
            //             $innerHTML .= $node->ownerDocument->saveHTML($child);
            //         }
            //
            //         // Add note to sidebar
            //         $pageContent['sidebar'] .= sprintf(
            //             '<p class="note">%s</p>',
            //             trim($innerHTML)
            //         );
            //     }
            //
            //     // Add closing tag to sidebar
            //     $pageContent['sidebar'] .= '</section>';
            // }
        }

        // Replace empty links
        $pageContent['sidebar'] = str_replace(
            '<h3><a href="#">Table Of Contents</a></h3>',
            '<h1>Table Of Contents</h1>',
            $pageContent['sidebar']
        );

        // Change headlines
        $pageContent['sidebar'] = str_replace('<h4>', '<h1>', $pageContent['sidebar']);
        $pageContent['sidebar'] = str_replace('</h4>', '</h1>', $pageContent['sidebar']);

        $pageContent['sidebar'] = str_replace('<h3>', '<h1>', $pageContent['sidebar']);
        $pageContent['sidebar'] = str_replace('</h3>', '</h1>', $pageContent['sidebar']);

        // Title
        $elem = $doc->queryXpath('//title')->current();
        $pageContent['title'] = $elem->nodeValue;

        return $pageContent;
    }

    /**
     * @param  string $path
     * @param  string $version
     * @return array
     */
    protected function getContentList(string $path, string $version)
    {
        if (strpos($version, '1.') === 0) {
            return [];
        }

        return $this->getV2ContentList($path);
    }

    /**
     * @param  string $path
     * @return array
     */
    protected function getV2ContentList(string $path)
    {
        // Create list
        $list = [
            'index.html' => 'Programmer’s Reference Guide',
        ];
        $doc  = new DomQuery(file_get_contents($path . 'index.html'));

        // Anonymous function for string replacement
        $replace = function ($string) {
            return str_replace('¶', '', $string);
        };

        // Fetch sections
        $sections = $doc->queryXpath(
            '//div[@class="body"]/div[@class="section"]/div[@class="section"]'
        );

        // Check sections
        if (count($sections)) {
            $xpath = new DOMXPath($sections->getDocument());

            foreach ($sections as $section) {
                // Get label for optgroup
                $group = $replace($section->childNodes->item(1)->nodeValue);
                if (empty($group)) {
                    $group = $replace($section->childNodes->item(2)->nodeValue);
                }

                // Create optgroup
                $list[$group] = [];

                // Fetch subsections
                $subSections = $xpath->query(
                    'div[@class="section"]',
                    $section
                );

                // Check subsections
                if ($subSections->length) {
                    foreach ($subSections as $subSection) {
                        // Fetch component headlines
                        $headlines = $xpath->query('h3', $subSection);

                        // Check component headlines
                        if ($headlines->length) {
                            // Fetch first link
                            $links = $xpath->query(
                                'blockquote/div/ul/li[1]/a',
                                $subSection
                            );

                            if (! $links->length) {
                                continue;
                            }

                            // Add to list
                            foreach ($headlines as $headline) {
                                $list[$group][$links->item(0)->getAttribute('href')] =
                                    $replace($headline->nodeValue);
                            }
                        }
                    }
                } else {
                    // Fetch page headlines
                    $headlines = $xpath->query(
                        'blockquote/div/ul/li/a',
                        $section
                    );

                    // Check page headlines
                    if ($headlines->length) {
                        // Add to list
                        foreach ($headlines as $headline) {
                            $list[$group][$headline->getAttribute('href')] =
                                $replace($headline->nodeValue);
                        }
                    }
                }
            }
        }

        return $list;
    }

    /**
     * @param  string $currentPage
     * @param  string $path
     * @param  string $version
     * @param  bool   $getHref
     * @return string|null
     */
    protected function getSelectedPage(string $currentPage, string $path, string $version, bool $getHref = true)
    {
        if (strpos($version, '1.') === 0) {
            return null;
        }

        return $this->getV2SelectedPage($currentPage, $path, $getHref);
    }

    /**
     * @param  string $currentPage
     * @param  string $path
     * @param  bool   $getHref
     * @return string|null
     */
    protected function getV2SelectedPage(string $currentPage, string $path, bool $getHref = true)
    {
        $doc = new DomQuery(file_get_contents($path . 'index.html'));

        if (true === $getHref) {
            // Fetch first link
            $links = $doc->queryXpath(
                sprintf(
                    '//a[@href = "%s"]/parent::li/parent::ul/li[1]/a',
                    $currentPage
                )
            );

            // Check link
            if ($links->count() && $links->current()->hasAttribute('href')) {
                return $links->current()->getAttribute('href');
            }
        } else {
            // Fetch headline (component name)
            $headline = $doc->queryXpath(
                sprintf(
                    '//a[@href = "%s"]/parent::li/parent::ul/parent::div/parent::blockquote/parent::div/h3',
                    $currentPage
                )
            );

            // Check headline
            if ($headline->count()) {
                return str_replace('¶', '', $headline->current()->nodeValue);
            } else {
                // Fetch headline
                $headline = $doc->queryXpath(
                    sprintf(
                        '//a[@href = "%s"]/parent::li/parent::ul/parent::div/parent::blockquote/parent::div/h2',
                        $currentPage
                    )
                );

                // Check headline
                if ($headline->count()) {
                    return str_replace('¶', '', $headline->current()->nodeValue);
                }
            }
        }

        return null;
    }

    private function log(string $message) : void
    {
        $message = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        $log     = fopen(realpath(getcwd()) . '/data/log/app.log', 'ab+');
        fwrite($log, $message);
        fclose($log);
    }
}
