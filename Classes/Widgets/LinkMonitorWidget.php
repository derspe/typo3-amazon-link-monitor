<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Widgets;

use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Dashboard widget that renders one view of the Amazon Link Monitor UI (KPIs, chart or list)
 * as web component. Which view is configured via the "view" option in Services.yaml.
 */
final readonly class LinkMonitorWidget implements WidgetRendererInterface, JavaScriptInterface
{
    /**
     * @param array{view?: string} $options
     */
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private SiteFinder $siteFinder,
        private array $options = [],
    ) {}

    public function getSettingsDefinitions(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sites[$site->getIdentifier()] = (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier();
        }
        if (count($sites) < 2) {
            return [];
        }
        return [
            new SettingDefinition(
                key: 'site',
                type: 'string',
                default: (string)array_key_first($sites),
                label: 'LLL:EXT:derspe_amazon_link_monitor/Resources/Private/Language/locallang.xlf:widget.settings.site',
                enum: $sites,
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $site = $context->settings->has('site') ? (string)$context->settings->get('site') : '';
        $content = sprintf(
            '<derspe-amazon-link-monitor-widget view="%s" site="%s"></derspe-amazon-link-monitor-widget>',
            htmlspecialchars((string)($this->options['view'] ?? ''), ENT_QUOTES),
            htmlspecialchars($site, ENT_QUOTES)
        );
        return new WidgetResult(content: $content, refreshable: true);
    }

    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@derspe/amazon-link-monitor/widget.js'),
        ];
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
