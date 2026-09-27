// The REST route map: every operation in Discord's OpenAPI description and what sends it.

import { builtFrom, classUrl, el, formatDate, lazyGroup, loadMap, openFromHash, slug, wireFilter } from './map.js';

function methodTag(method) {
  return el('span', { class: `method ${method.toLowerCase()}` }, method);
}

function senders(route) {
  if (route.sentBy.length === 0) {
    return el('span', { class: 'tag warn' }, 'not sent');
  }
  return el(
    'ul',
    { class: 'plain' },
    route.sentBy.map((sender) => el('li', {}, el('a', { href: classUrl(sender.fqcn, sender.member) }, el('code', {}, `${sender.class}::${sender.member}`)))),
  );
}

function routeRow(route) {
  const search = [route.method, route.path, route.id, ...route.constants, ...route.sentBy.map((s) => `${s.class}::${s.member}`)].join(' ').toLowerCase();
  return el(
    'tr',
    { dataset: { search, missing: route.sentBy.length === 0 ? 'true' : 'false' } },
    el('td', {}, methodTag(route.method)),
    el('td', {}, el('code', {}, route.path), route.deprecated ? [' ', el('span', { class: 'tag warn' }, 'deprecated')] : null),
    el('td', {}, senders(route)),
    el('td', {}, route.constants.length ? route.constants.map((c, i) => [i ? ', ' : '', el('code', {}, c)]) : '—'),
    el('td', {}, route.auth.join(', ') || '—'),
  );
}

function group(data) {
  const missing = data.routes.filter((route) => route.sentBy.length === 0).length;
  return lazyGroup({
    id: slug(data.name),
    title: data.name,
    extra: [
      el('span', { class: 'count' }, `${data.routes.length} route${data.routes.length === 1 ? '' : 's'}`),
      missing ? el('span', { class: 'tag warn' }, `${missing} not sent`) : null,
    ],
    diagram: data.mermaid,
    body: [
      el(
        'div',
        { class: 'table-wrap' },
        el(
          'table',
          {},
          el('thead', {}, el('tr', {}, ['Method', 'Route', 'Sent by', 'Endpoint constant', 'Auth'].map((h) => el('th', { scope: 'col' }, h)))),
          el('tbody', {}, data.routes.map(routeRow)),
        ),
      ),
    ],
  });
}

async function main() {
  const root = document.getElementById('route-map');
  try {
    const map = await loadMap('../data/routes.json');
    const t = map.totals;

    document.getElementById('route-stats').replaceChildren(
      el('div', { class: 'stat' }, el('strong', {}, t.operations), el('span', {}, 'REST operations')),
      el('div', { class: 'stat' }, el('strong', {}, t.sent), el('span', {}, 'sent by DiscordPHP')),
      el('div', { class: 'stat' }, el('strong', {}, t['not sent']), el('span', {}, `not sent, ${t.deprecated} of them deprecated`)),
      el('div', { class: 'stat' }, el('strong', {}, t.groups), el('span', {}, 'diagrams')),
    );
    document.getElementById('route-source').replaceChildren(
      'Built from ', ...builtFrom(map), ` and ${map.spec.title}${map.spec.version ? ` ${map.spec.version}` : ''}, `, formatDate(map.generated), '.',
    );

    const groups = map.groups.map(group);
    root.replaceChildren(...groups);
    wireFilter({
      input: document.getElementById('route-filter'),
      checkbox: document.getElementById('route-missing'),
      groups,
      counter: document.getElementById('route-count'),
      rowMatches: (row, query, missingOnly) => (!missingOnly || row.dataset.missing === 'true') && (query === '' || row.dataset.search.includes(query)),
    });
    openFromHash();
  } catch (error) {
    root.replaceChildren(el('p', { class: 'note' }, 'The route map could not be loaded. Try reloading the page.'));
    console.error(error);
  }
}

document.addEventListener('DOMContentLoaded', main);
