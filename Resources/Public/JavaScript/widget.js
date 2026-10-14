/**
 * <derspe-amazon-link-monitor-widget view="status|broken|history" site="…">: dashboard widgets.
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, loadMeta, skeleton, t } from '@derspe/amazon-link-monitor/core.js';
import { historyChart, lastCrawlText, linkHref, statCards, statusBadge } from '@derspe/amazon-link-monitor/views.js';

class AmazonLinkMonitorWidget extends SiElement {
  static properties = {
    view: { type: String }, data: { state: true }, error: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { height: 100%; font-size: 13px; padding: 12px 16px; container-type: inline-size; }
      .grid-kpis.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
      .stat, .stat.compact { padding: 8px 12px; min-height: 0; }
      .stat .value, .stat.compact .value { font-size: 18px; }
      @container (max-width: 520px) { .grid-kpis.cols-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
      .footer { margin-top: 8px; }
      ul { margin: 0; padding: 0; list-style: none; }
      li { display: flex; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--si-border); }
      li:last-child { border-bottom: 0; }
      li .main { flex: 1 1 auto; min-width: 0; }
      li a { color: var(--si-primary-light-color); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      li a:hover { text-decoration: underline; }
    `,
  ];

  constructor() {
    super();
    this.view = '';
    this.data = null;
    this.error = null;
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta().then((meta) => {
      if (!this.site || !meta.sites.some((s) => s.identifier === this.site)) {
        this.site = meta.sites[0]?.identifier ?? '';
      }
      return this.load();
    }).catch((e) => { this.error = e; });
  }

  async load() {
    try {
      if (this.view === 'status') {
        this.data = await this.api('stats');
      } else if (this.view === 'broken') {
        const pages = await this.api('links');
        this.data = pages.flatMap((p) => p.links.filter((l) => l.status === 'broken' || l.status === 'warning').map((l) => ({ ...l, page: p })));
      } else if (this.view === 'history') {
        this.data = await this.api('runs', { params: { days: 30 } });
      }
    } catch (e) {
      this.error = e;
    }
  }

  render() {
    if (this.error) {
      return errorState(this.error);
    }
    switch (this.view) {
      case 'status':
        return html`${statCards(this.data, true)}<div class="xs dimmed footer">${t('crawl.last', { date: lastCrawlText(this.data) })}</div>`;
      case 'history':
        return html`${historyChart(this.data, 260)}<div class="xs dimmed footer">${t('preset.days', { n: 30 })}</div>`;
      case 'broken':
        if (!this.data) {
          return skeleton(240);
        }
        if (this.data.length === 0) {
          return html`<div class="empty">${t('widget.noBroken')}</div>`;
        }
        return html`<ul>${this.data.slice(0, 25).map((l) => html`<li>
          ${statusBadge(l)}
          <div class="main">
            <a href=${linkHref(l.url)} target="_blank" rel="noopener noreferrer" title=${l.url}>${l.productTitle || l.url}</a>
            <div class="xs dimmed truncate" title=${l.page.url}>${l.page.title || fmt.path(l.page.url)}</div>
          </div>
        </li>`)}</ul>
        ${this.data.length > 25 ? html`<div class="xs dimmed footer">${t('widget.more', { n: this.data.length - 25 })}</div>` : nothing}`;
      default:
        return nothing;
    }
  }
}

customElements.define('derspe-amazon-link-monitor-widget', AmazonLinkMonitorWidget);
