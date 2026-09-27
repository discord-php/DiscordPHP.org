// The gateway event map: every dispatch event DiscordPHP handles and the class that handles it.

import { REFERENCE, builtFrom, classUrl, el, formatDate, lazyGroup, loadMap, openFromHash, slug, wireFilter } from './map.js';

/** The guide's page for each category, where it has one. */
const GUIDE = {
  Applications: 'guide/events/application_commands.html',
  'Auto moderation': 'guide/events/auto_moderations.html',
  Channels: 'guide/events/channels.html',
  Guilds: 'guide/events/guilds.html',
  Interactions: 'guide/events/interactions.html',
  Invites: 'guide/events/invites.html',
  Messages: 'guide/events/messages.html',
  'Stage instances': 'guide/events/stage_instances.html',
  'Users and presence': 'guide/events/presences.html',
  Voice: 'guide/events/voices.html',
  Webhooks: 'guide/events/webhooks.html',
};

function eventRow(event) {
  return el(
    'tr',
    { dataset: { search: `${event.event} ${event.handler}`.toLowerCase() } },
    el('td', {}, el('code', {}, `Event::${event.event}`)),
    el('td', {}, el('a', { href: classUrl(event.fqcn) }, el('code', {}, event.handler))),
  );
}

function group(data, byName) {
  const events = data.events.map((name) => byName.get(name));
  const guide = GUIDE[data.name];
  return lazyGroup({
    id: slug(data.name),
    title: data.name,
    extra: [el('span', { class: 'count' }, `${events.length} event${events.length === 1 ? '' : 's'}`)],
    diagram: data.mermaid,
    body: [
      guide ? el('p', {}, 'The guide describes these events and what your listener receives: ', el('a', { href: REFERENCE + guide }, `${data.name} events`), '.') : null,
      el(
        'div',
        { class: 'table-wrap' },
        el(
          'table',
          {},
          el('thead', {}, el('tr', {}, ['Event', 'Handler'].map((h) => el('th', { scope: 'col' }, h)))),
          el('tbody', {}, events.map(eventRow)),
        ),
      ),
    ],
  });
}

async function main() {
  const root = document.getElementById('event-map');
  try {
    const map = await loadMap('../data/routes.json');
    const byName = new Map(map.events.map((event) => [event.event, event]));

    document.getElementById('event-source').replaceChildren(
      `${map.events.length} events in ${map.eventGroups.length} groups, from `, ...builtFrom(map), ', ', formatDate(map.generated), '.',
    );

    const groups = map.eventGroups.map((data) => group(data, byName));
    root.replaceChildren(...groups);
    wireFilter({
      input: document.getElementById('event-filter'),
      groups,
      counter: document.getElementById('event-count'),
      rowMatches: (row, query) => query === '' || row.dataset.search.includes(query),
    });
    openFromHash();
  } catch (error) {
    root.replaceChildren(el('p', { class: 'note' }, 'The event map could not be loaded. Try reloading the page.'));
    console.error(error);
  }
}

document.addEventListener('DOMContentLoaded', main);
