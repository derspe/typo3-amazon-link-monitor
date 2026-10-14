/**
 * Shared views of the module and the dashboard widgets.
 */
import { html, nothing } from 'lit';
import { badge, fmt, skeleton, statCard, t } from '@derspe/amazon-link-monitor/core.js';
import '@derspe/amazon-link-monitor/chart.js';

export const STATUS_COLORS = { ok: 'green', broken: 'red', warning: 'yellow', unknown: 'gray', '': 'gray' };

export function statusBadge(link) {
  const status = link.status ?? '';
  const label = status === '' ? t('status.unchecked') : t(`status.${status}`);
  const code = link.statusCode ? ` ${link.statusCode}` : '';
  return html`<span title=${reasonText(link)}>${badge(`${label}${status === 'ok' ? '' : code}`, STATUS_COLORS[status] ?? 'gray')}</span>`;
}

export function reasonText(link) {
  if (!link.reason) {
    return '';
  }
  const key = `reason.${link.reason}`;
  const label = t(key);
  return label === key ? link.reason : label;
}

export function statCards(stats, compact = false) {
  if (!stats) {
    return html`<div class="grid-kpis cols-4">${[0, 1, 2, 3].map(() => html`<div class="stat">${skeleton(48)}</div>`)}</div>`;
  }
  return html`<div class="grid-kpis cols-4">
    ${statCard({ compact, label: t('stats.broken'), value: fmt.int(stats.broken), color: 'red', icon: 'alertCircle' })}
    ${statCard({ compact, label: t('stats.warning'), value: fmt.int(stats.warning + stats.unknown + stats.unchecked), color: 'yellow', icon: 'infoCircle',
      title: t('stats.warning.hint') })}
    ${statCard({ compact, label: t('stats.ok'), value: fmt.int(stats.ok), color: 'green', icon: 'check' })}
    ${statCard({ compact, label: t('stats.total'), value: fmt.int(stats.total), color: 'blue', icon: 'link',
      sub: html`<div class="xs dimmed">${t('stats.pages', { n: fmt.int(stats.pages) })}</div>` })}
  </div>`;
}

export function historyChart(runs, height = 200) {
  if (!runs) {
    return skeleton(height);
  }
  if (runs.length === 0) {
    return html`<div class="empty">${t('history.empty')}</div>`;
  }
  const data = runs.map((r) => ({
    date: new Date(r.started_at * 1000).toISOString().slice(0, 10),
    total: r.total,
    ok: r.ok,
    broken: r.broken,
    warning: r.warning + r.unknown,
  }));
  return html`<alm-chart type="area" height=${height} legend ?dots=${data.length <= 30} .data=${data} .series=${[
    { key: 'total', label: t('stats.total'), color: 'blue' },
    { key: 'ok', label: t('stats.ok'), color: 'green' },
    { key: 'warning', label: t('stats.warning'), color: 'yellow' },
    { key: 'broken', label: t('stats.broken'), color: 'red' },
  ]}></alm-chart>`;
}

export function lastCrawlText(stats) {
  return stats?.lastCrawledAt ? new Date(stats.lastCrawledAt * 1000).toLocaleString() : t('stats.neverCrawled');
}

export function linkHref(url) {
  return /^https?:\/\//i.test(url) ? url : nothing;
}
