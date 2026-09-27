// Shared by the route and event maps: loading the generated data and building their groups.

import { renderDiagram } from './site.js';

export const REFERENCE = 'https://discord-php.github.io/DiscordPHP/';

/** Builds an element: el('a', { href: '…' }, 'text', child, …). */
export function el(tag, attributes = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (value === false || value === null || value === undefined) {
      continue;
    }
    if (name === 'class') {
      node.className = value;
    } else if (name === 'dataset') {
      Object.assign(node.dataset, value);
    } else {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }
  for (const child of children.flat()) {
    if (child !== null && child !== undefined && child !== false) {
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
  }
  return node;
}

export function slug(text) {
  return text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

/** The class reference page for a class, and optionally one of its methods. */
export function classUrl(fqcn, member = null) {
  const page = `${REFERENCE}classes/${fqcn.replaceAll('\\', '-')}.html`;
  return member ? `${page}#method_${member.replace(/\(\)$/, '')}` : page;
}

export async function loadMap(url) {
  const response = await fetch(url, { cache: 'no-cache' });
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  return response.json();
}

export function formatDate(iso) {
  return new Date(iso).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' });
}

/** "DiscordPHP v10.60.0 (bd9060384)", linked to the release and the commit the map was built from. */
export function builtFrom(map) {
  const commit = el('a', { href: `https://github.com/discord-php/DiscordPHP/commit/${map.discordphp}` }, el('code', {}, map.discordphp.slice(0, 9)));
  if (!map.release) {
    return ['DiscordPHP ', commit];
  }
  return ['DiscordPHP ', el('a', { href: `https://github.com/discord-php/DiscordPHP/releases/tag/${encodeURIComponent(map.release)}` }, map.release), ' (', commit, ')'];
}

/**
 * A collapsible group whose diagram is drawn the first time it is opened, since drawing every
 * diagram on load would take seconds.
 */
export function lazyGroup({ id, title, extra = [], diagram, body = [] }) {
  const pre = el('pre', { class: 'mermaid', dataset: { lazy: 'true' } }, diagram);
  const details = el(
    'details',
    { class: 'group', id },
    el('summary', {}, el('span', {}, title), ...extra),
    el('div', { class: 'group-body' }, el('div', { class: 'diagram' }, pre), ...body),
  );
  details.addEventListener('toggle', () => {
    if (details.open && !pre.dataset.rendered && !pre.dataset.queued) {
      pre.dataset.queued = 'true';
      renderDiagram(pre);
    }
  });
  return details;
}

function openTarget() {
  const id = decodeURIComponent(window.location.hash.slice(1));
  const target = id ? document.getElementById(id) : null;
  if (target instanceof HTMLDetailsElement) {
    target.hidden = false;
    target.open = true;
    target.scrollIntoView();
  }
}

/** Opens the group named in the address bar once the groups exist, and whenever the hash changes. */
export function openFromHash() {
  openTarget();
  window.addEventListener('hashchange', openTarget);
}

/** Filters table rows by their data-search text, hiding groups left with none. */
export function wireFilter({ input, checkbox = null, groups, rowMatches, counter }) {
  const apply = () => {
    const query = input.value.trim().toLowerCase();
    let shown = 0;
    for (const group of groups) {
      let visible = 0;
      for (const row of group.querySelectorAll('tbody tr')) {
        const match = rowMatches(row, query, checkbox?.checked ?? false);
        row.hidden = !match;
        visible += match ? 1 : 0;
      }
      group.hidden = visible === 0;
      if (visible > 0 && (query !== '' || checkbox?.checked)) {
        group.open = true;
      }
      shown += visible;
    }
    counter.textContent = query === '' && !checkbox?.checked ? '' : `${shown} match${shown === 1 ? '' : 'es'}`;
  };
  input.addEventListener('input', apply);
  checkbox?.addEventListener('change', apply);
  return apply;
}
