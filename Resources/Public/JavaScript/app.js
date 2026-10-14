/**
 * <derspe-amazon-link-monitor-app>: backend module.
 */
import { css, html, nothing } from 'lit';
import {
  SiElement, alertBox, api, badge, errorState, fmt, getMeta, icon, loadMeta, segmented, selectInput, skeleton, spinnerButton, t,
} from '@derspe/amazon-link-monitor/core.js';
import { JobRunner, jobProgressText } from '@derspe/amazon-link-monitor/jobs.js';
import { STATUS_COLORS, historyChart, lastCrawlText, linkHref, reasonText, statCards, statusBadge } from '@derspe/amazon-link-monitor/views.js';

const STORAGE_KEY = 'derspe-amazon-link-monitor';

function readState() {
  try {
    return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
  } catch {
    return {};
  }
}

function writeState(state) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    // storage not available
  }
}

class AmazonLinkMonitorApp extends SiElement {
  static properties = {
    meta: { state: true }, error: { state: true }, tab: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { padding: 4px 0 32px; }
      header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
      .brand { display: flex; align-items: center; gap: 10px; }
      .brand .logo { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 8px;
        background: color-mix(in srgb, var(--si-orange-6) 15%, transparent); color: var(--si-orange-6); }
    `,
  ];

  constructor() {
    super();
    const state = readState();
    this.meta = null;
    this.error = null;
    this.tab = state.tab ?? 'links';
    this.site = state.site ?? '';
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta().then((meta) => {
      this.meta = meta;
      if (!meta.sites.some((s) => s.identifier === this.site)) {
        this.site = meta.sites[0]?.identifier ?? '';
      }
    }).catch((e) => { this.error = e; });
  }

  persist() {
    writeState({ tab: this.tab, site: this.site });
  }

  render() {
    if (this.error) {
      return errorState(this.error);
    }
    if (!this.meta) {
      return html`<div class="stack">${skeleton(40, '320px')}${skeleton(300)}</div>`;
    }
    const tabs = [['links', 'link', t('tab.links')], ['settings', 'settings', t('tab.settings')]];
    return html`
      <header>
        <div class="brand"><span class="logo">${icon('link', 20)}</span><h2 class="title">${t('app.title')}</h2></div>
        ${this.meta.sites.length > 1 ? html`<div class="ml-auto">${selectInput(
          this.meta.sites.map((s) => ({ value: s.identifier, label: s.title })), this.site,
          (site) => { this.site = site; this.persist(); }, { size: '', minWidth: 220, label: t('app.site') },
        )}</div>` : nothing}
      </header>
      <div class="tabs" role="tablist">
        ${tabs.map(([key, iconName, label]) => html`<button type="button" role="tab" class="tab ${this.tab === key ? 'active' : ''}"
          aria-selected=${this.tab === key ? 'true' : 'false'} @click=${() => { this.tab = key; this.persist(); }}>${icon(iconName, 14)}${label}</button>`)}
      </div>
      ${this.tab === 'links'
        ? html`<alm-links .site=${this.site} @alm-open-settings=${() => { this.tab = 'settings'; this.persist(); }}></alm-links>`
        : html`<alm-settings .site=${this.site}></alm-settings>`}
    `;
  }
}

// ---------------------------------------------------------------------------
// Links overview
// ---------------------------------------------------------------------------

class AlmLinks extends SiElement {
  static properties = {
    stats: { state: true }, pages: { state: true }, runs: { state: true }, events: { state: true },
    days: { state: true }, job: { state: true }, error: { state: true },
    filter: { state: true }, search: { state: true }, open: { state: true }, allOpen: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      .page { border-left: 3px solid var(--si-green-6); }
      .page.has-broken { border-left-color: var(--si-red-6); }
      .page.has-warning { border-left-color: var(--si-yellow-6); }
      .page-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding-bottom: 10px; margin-bottom: 6px; border-bottom: 1px solid var(--si-border); }
      .page-head a { color: var(--si-text-strong); font-weight: 600; font-size: 14px; text-decoration: none; }
      .page-head a:hover { text-decoration: underline; }
      .row { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 4px; cursor: pointer; }
      .row:hover { background: var(--si-hover); }
      .row a { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--si-primary-light-color); font-size: 13px; text-decoration: none; }
      .row a:hover { text-decoration: underline; }
      .row .product { flex: 0 1 40%; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--si-dimmed); font-size: 13px; text-align: right; }
      .details { margin: 2px 0 6px 32px; padding: 8px 12px; border-radius: 4px; background: var(--si-surface-alt); font-size: 12px; color: var(--si-dimmed); display: grid; gap: 3px; }
      .details b { font-weight: 600; }
      .chev { color: var(--si-dimmed); transition: transform .15s; }
      .chev.open { transform: rotate(180deg); }
      .events { font-size: 12px; }
      .events li { display: flex; gap: 8px; align-items: center; padding: 4px 0; border-bottom: 1px solid var(--si-border); list-style: none; }
      .events li:last-child { border-bottom: 0; }
      .events ul { margin: 0; padding: 0; }
      .events .url { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    `,
  ];

  constructor() {
    super();
    this.stats = null;
    this.pages = null;
    this.runs = null;
    this.events = null;
    this.days = 30;
    this.job = null;
    this.error = null;
    this.filter = 'all';
    this.search = '';
    this.open = new Set();
    this.allOpen = false;
    this.runner = null;
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site) {
      this.runner?.stop();
      this.runner = new JobRunner(this.site, (job) => { this.job = job; });
      this.load();
      this.runner.resume('crawl').then((job) => job && this.load()).catch(() => {});
    }
    if (changed.has('days') && this.site) {
      this.loadRuns();
    }
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.runner?.stop();
  }

  async load() {
    this.error = null;
    try {
      const [stats, pages, events] = await Promise.all([this.api('stats'), this.api('links'), this.api('events', { params: { limit: 15 } })]);
      this.stats = stats;
      this.pages = pages;
      this.events = events;
    } catch (e) {
      this.error = e;
    }
    this.loadRuns();
  }

  loadRuns() {
    this.runs = null;
    this.api('runs', { params: { days: this.days } }).then((r) => { this.runs = r; }).catch(() => { this.runs = []; });
  }

  async crawl() {
    try {
      await this.runner.start('crawl');
    } catch (e) {
      this.error = e;
    }
    this.load();
  }

  toggle(id) {
    const open = new Set(this.open);
    if (open.has(id)) {
      open.delete(id);
    } else {
      open.add(id);
    }
    this.open = open;
  }

  toggleAll() {
    this.allOpen = !this.allOpen;
    this.open = this.allOpen ? new Set((this.pages ?? []).flatMap((p) => p.links.map((l) => l.id))) : new Set();
  }

  filteredPages() {
    const needle = this.search.trim().toLowerCase();
    const matchStatus = (l) => {
      switch (this.filter) {
        case 'broken': return l.status === 'broken';
        case 'warning': return l.status === 'warning' || l.status === 'unknown' || l.status === '';
        case 'ok': return l.status === 'ok';
        default: return true;
      }
    };
    const matchText = (p, l) => !needle || [p.url, p.title, l.url, l.asin, l.productTitle, l.text].some((v) => (v ?? '').toLowerCase().includes(needle));
    return (this.pages ?? [])
      .map((p) => ({ ...p, links: p.links.filter((l) => matchStatus(l) && matchText(p, l)) }))
      .filter((p) => p.links.length > 0);
  }

  render() {
    const running = this.job && (this.job.status === 'running' || this.job.status === 'pending');
    const progress = this.job?.total ? Math.round((this.job.done / this.job.total) * 100) : 0;
    return html`<div class="stack">
      ${this.error ? errorState(this.error) : nothing}
      ${this.stats && !this.stats.apiConfigured ? alertBox(html`${t('hint.noApi')} <button type="button" class="btn xs light" style="margin-left:8px"
        @click=${() => this.dispatchEvent(new CustomEvent('alm-open-settings'))}>${t('action.openSettings')}</button>`, { color: 'yellow' }) : nothing}

      <div class="paper">
        <div class="group between mb-sm">
          <span class="card-title" style="margin:0">${t('history.title')}</span>
          ${segmented([7, 30, 90, 0].map((d) => ({ label: d ? t('preset.days', { n: d }) : t('preset.all'), value: d, active: this.days === d })), (item) => { this.days = item.value; })}
        </div>
        ${historyChart(this.runs)}
      </div>

      ${statCards(this.stats)}

      <div class="paper">
        <div class="group between">
          <div class="group">
            ${spinnerButton(t('crawl.start'), { loading: running, iconName: 'refresh', onClick: () => this.crawl() })}
            <span class="sm dimmed">${t('crawl.last', { date: lastCrawlText(this.stats) })}</span>
          </div>
        </div>
        ${this.job ? html`<div class="stack-xs mt">
          ${running ? html`<div class="progress"><div style="width:${progress}%"></div></div>` : nothing}
          <span class="xs truncate ${this.job.status === 'error' ? 'c-red' : (this.job.status === 'done' ? 'c-green' : 'dimmed')}">${jobProgressText(this.job)}</span>
        </div>` : nothing}
      </div>

      ${this.renderEvents()}
      ${this.renderPages()}
    </div>`;
  }

  renderEvents() {
    if (!this.events || this.events.length === 0) {
      return nothing;
    }
    return html`<div class="paper events">
      <h4 class="card-title">${t('events.title')}</h4>
      <ul>${this.events.map((e) => html`<li>
        <span class="dimmed" style="white-space:nowrap">${fmt.date(new Date(e.createdAt * 1000).toISOString())}</span>
        ${badge(t(`event.${e.event}`), e.event === 'removed' ? 'gray' : (STATUS_COLORS[e.event] ?? 'blue'), 'light')}
        <span class="url" title=${e.linkUrl}>${e.linkUrl}</span>
        <span class="dimmed truncate" style="max-width:35%" title=${e.pageUrl}>${fmt.path(e.pageUrl)}</span>
      </li>`)}</ul>
    </div>`;
  }

  renderPages() {
    if (!this.pages) {
      return skeleton(300);
    }
    if (this.pages.length === 0) {
      return html`<div class="paper"><div class="empty">${t('links.empty')}</div></div>`;
    }
    const pages = this.filteredPages();
    const filters = [
      { value: 'all', label: t('filter.all') },
      { value: 'broken', label: t('filter.broken') },
      { value: 'warning', label: t('filter.warning') },
      { value: 'ok', label: t('filter.ok') },
    ];
    return html`
      <div class="group">
        ${selectInput(filters, this.filter, (v) => { this.filter = v; }, { minWidth: 170 })}
        <input class="input xs" type="search" style="max-width:300px" placeholder=${t('links.search')} .value=${this.search}
          @input=${(e) => { this.search = e.target.value; }}>
        <button type="button" class="btn xs subtle ml-auto" @click=${() => this.toggleAll()}>
          <span class="chev ${this.allOpen ? 'open' : ''}">${icon('chevronDown', 12)}</span>${this.allOpen ? t('links.collapseAll') : t('links.expandAll')}</button>
      </div>
      ${pages.length === 0 ? html`<div class="paper"><div class="empty">${t('links.noMatch')}</div></div>` : nothing}
      ${pages.map((page) => html`
        <div class="paper page ${page.broken ? 'has-broken' : (page.warning ? 'has-warning' : '')}">
          <div class="page-head">
            <div style="min-width:0">
              <a href=${linkHref(page.url)} target="_blank" rel="noopener">${page.title || page.url}</a>
              <div class="xs dimmed truncate">${page.url} · ${t('links.count', { n: page.total })}</div>
            </div>
            ${page.broken ? badge(t('links.brokenCount', { n: page.broken }), 'red', 'light')
              : page.warning ? badge(t('links.warningCount', { n: page.warning }), 'yellow', 'light')
              : badge(t('links.allOk'), 'green', 'light')}
          </div>
          ${page.links.map((link) => this.renderLink(link))}
        </div>`)}`;
  }

  renderLink(link) {
    const isOpen = this.open.has(link.id);
    return html`
      <div class="row" @click=${() => this.toggle(link.id)}>
        <span class="chev ${isOpen ? 'open' : ''}">${icon('chevronDown', 14)}</span>
        ${statusBadge(link)}
        ${link.occurrences > 1 ? badge(`${link.occurrences}×`, 'blue', 'light') : nothing}
        <a href=${linkHref(link.url)} target="_blank" rel="noopener noreferrer" @click=${(e) => e.stopPropagation()}>${link.url}</a>
        <span class="product" title=${link.productTitle}>${link.productTitle || '–'}</span>
      </div>
      ${isOpen ? html`<div class="details">
        ${link.context ? html`<div><b>${t('detail.context')}:</b> „…${link.context}…“</div>` : nothing}
        ${link.text ? html`<div><b>${t('detail.text')}:</b> ${link.text}</div>` : nothing}
        ${link.asin ? html`<div><b>ASIN:</b> ${link.asin}</div>` : nothing}
        ${link.affiliateId ? html`<div><b>${t('detail.affiliateId')}:</b> ${link.affiliateId}</div>` : nothing}
        ${link.reason ? html`<div><b>${t('detail.reason')}:</b> ${reasonText(link)}</div>` : nothing}
        ${link.availability ? html`<div><b>${t('detail.availability')}:</b> ${link.availability}</div>` : nothing}
        <div><b>${t('detail.method')}:</b> ${link.method ? t(`method.${link.method}`) : '–'}</div>
        <div><b>${t('detail.lastChecked')}:</b> ${link.lastCheckedAt ? new Date(link.lastCheckedAt * 1000).toLocaleString() : '–'}</div>
      </div>` : nothing}`;
  }
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

class AlmSettings extends SiElement {
  static properties = {
    form: { state: true }, values: { state: true }, saving: { state: true }, saved: { state: true }, error: { state: true },
  };

  constructor() {
    super();
    this.form = null;
    this.values = null;
    this.saving = false;
    this.saved = false;
    this.error = null;
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site) {
      this.load();
    }
  }

  async load() {
    try {
      const v = await this.api('settings');
      this.values = v;
      this.form = {
        domains: (v.domains ?? []).join(', '),
        sitemap_url: v.sitemap_url ?? '',
        api_credential_id: v.api_credential_id ?? '',
        api_credential_secret: '',
        api_credential_version: v.api_credential_version ?? '',
        api_partner_tag: v.api_partner_tag ?? '',
        api_marketplace: v.api_marketplace ?? '',
        api_token_url: v.api_token_url ?? '',
        api_base_url: v.api_base_url ?? '',
      };
    } catch (e) {
      this.error = e;
    }
  }

  async save() {
    this.saving = true;
    this.error = null;
    try {
      await this.api('settings', { method: 'POST', body: { values: this.form } });
      await this.load();
      this.saved = true;
      setTimeout(() => { this.saved = false; }, 2000);
    } catch (e) {
      this.error = e;
    } finally {
      this.saving = false;
    }
  }

  field(key, label, { description = '', placeholder = '', type = 'text' } = {}) {
    return html`<div class="field">
      <label for="f-${key}">${label}</label>
      ${description ? html`<span class="description">${description}</span>` : nothing}
      <input id="f-${key}" class="input" type=${type} .value=${String(this.form[key] ?? '')} placeholder=${placeholder}
        ?disabled=${!getMeta().isAdmin} autocomplete="off" @input=${(e) => { this.form = { ...this.form, [key]: e.target.value }; }}>
    </div>`;
  }

  render() {
    if (!this.form) {
      return this.error ? errorState(this.error) : skeleton(400);
    }
    const isAdmin = getMeta().isAdmin;
    return html`<div class="paper" style="max-width:860px">
      <h3 class="title mb">${t('settings.title')}</h3>
      <div class="stack-sm">
        ${!isAdmin ? alertBox(t('settings.adminOnly'), { color: 'yellow' }) : nothing}
        ${this.error ? alertBox(this.error.message) : nothing}
        ${this.field('domains', t('settings.domains'), { description: t('settings.domains.description'), placeholder: 'amazon.de, amzn.to' })}
        ${this.field('sitemap_url', t('settings.sitemap'), { description: t('settings.sitemap.description'), placeholder: this.values.sitemap_url_default || 'https://example.com/sitemap.xml' })}

        <div class="paper alt mt">
          <div class="group between mb-sm">
            <span class="fw6">${t('settings.api')}</span>
            ${badge(this.values.api_configured ? t('settings.api.active') : t('settings.api.inactive'), this.values.api_configured ? 'green' : 'gray')}
          </div>
          <div class="sm dimmed mb">${t('settings.api.description')}</div>
          <div class="stack-sm">
            ${this.field('api_credential_id', t('settings.api.credentialId'))}
            ${this.field('api_credential_secret', t('settings.api.credentialSecret'), {
              type: 'password', placeholder: this.values.api_credential_secret_set ? t('settings.secretSet') : '',
            })}
            ${this.field('api_partner_tag', t('settings.api.partnerTag'), { description: t('settings.api.partnerTag.description'), placeholder: 'example-21' })}
            ${this.field('api_marketplace', t('settings.api.marketplace'), { placeholder: 'www.amazon.de' })}
            ${this.field('api_credential_version', t('settings.api.version'), { placeholder: '2.2' })}
            ${this.field('api_token_url', t('settings.api.tokenUrl'), { description: t('settings.api.tokenUrl.description') })}
            ${this.field('api_base_url', t('settings.api.baseUrl'))}
          </div>
        </div>

        ${isAdmin ? html`<div>${spinnerButton(this.saved ? t('settings.saved') : t('settings.save'), {
          loading: this.saving, color: this.saved ? 'success' : '', onClick: () => this.save(),
        })}</div>` : nothing}
        <div class="xs dimmed">${t('settings.scheduler')}</div>
      </div>
    </div>`;
  }
}

customElements.define('derspe-amazon-link-monitor-app', AmazonLinkMonitorApp);
customElements.define('alm-links', AlmLinks);
customElements.define('alm-settings', AlmSettings);
