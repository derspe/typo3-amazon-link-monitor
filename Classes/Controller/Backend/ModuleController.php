<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module shell. The UI itself is a set of Lit web components that talk to {@see ApiController}.
 */
#[AsController]
final readonly class ModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Amazon Link Monitor');
        $this->pageRenderer->loadJavaScriptModule('@derspe/amazon-link-monitor/app.js');
        return $view->renderResponse('Module/Index');
    }
}
