// The newsletter page: shows one edition from data/newsletter.json (the newest, or the one named in
// the URL's #fragment) and lists the rest as an archive.
//
// Editions are written by the DiscordPHP-Newsletter bot in Discord-flavoured markdown. Everything is
// escaped before the small subset of formatting below is applied, so an edition can never inject HTML.

const DATA = 'data/newsletter.json';
const ECOSYSTEM_DATA = 'data/ecosystem.json';

/** The bot that writes the editions. Every edition links to its source code. */
const SOURCE = 'https://github.com/Valgorithms/DiscordPHP-Newsletter';

function escapeHtml(text) {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Bold, italics, inline code, links and owner/repo#123 references, on already-escaped text. */
function inline(text) {
  const codes = [];
  let html = escapeHtml(text).replace(/`([^`]+)`/g, (_, code) => {
    codes.push(`<code>${code}</code>`);
    return `\u0000${codes.length - 1}\u0000`;
  });
  html = html
    .replace(/\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>')
    .replace(/(^|[\s(])(https:\/\/[^\s<)]+)/g, '$1<a href="$2">$2</a>')
    .replace(/(^|[\s(])([A-Za-z0-9-]+\/[A-Za-z0-9._-]+)#(\d+)\b/g, '$1<a href="https://github.com/$2/issues/$3">$2#$3</a>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*\w])\*([^*\s][^*]*)\*(?!\w)/g, '$1<em>$2</em>')
    .replace(/(^|[^_\w])_([^_\s][^_]*)_(?!\w)/g, '$1<em>$2</em>');

  return html.replace(/\u0000(\d+)\u0000/g, (_, i) => codes[Number(i)]);
}

/** Paragraphs, bullet lists and small headings. */
function block(markdown) {
  const out = [];
  let list = null;
  let paragraph = [];
  const flushParagraph = () => {
    if (paragraph.length) {
      out.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
      paragraph = [];
    }
  };
  const flushList = () => {
    if (list) {
      out.push(`<ul>${list.map((item) => `<li>${inline(item)}</li>`).join('')}</ul>`);
      list = null;
    }
  };

  for (const raw of markdown.split('\n')) {
    const line = raw.trimEnd();
    const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
    const heading = line.match(/^#{1,6}\s+(.*)$/);
    if (bullet) {
      flushParagraph();
      (list ??= []).push(bullet[1]);
    } else if (heading) {
      flushParagraph();
      flushList();
      out.push(`<h4>${inline(heading[1])}</h4>`);
    } else if (line.trim() === '') {
      flushParagraph();
      flushList();
    } else {
      flushList();
      paragraph.push(line.replace(/^-#\s+/, ''));
    }
  }
  flushParagraph();
  flushList();

  return out.join('');
}

function formatDate(edition) {
  const date = new Date(`${edition.date}T12:00:00`);
  return Number.isNaN(date.getTime())
    ? edition.date
    : date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

function renderEdition(article, edition, labels) {
  const sections = (edition.sections ?? [])
    .map((s) => `<section>${s.title ? `<h3>${inline(s.title)}</h3>` : ''}${block(s.body ?? '')}</section>`)
    .join('');
  const tags = (edition.tags ?? [])
    .filter((tag) => /^[a-z0-9-]{1,32}$/.test(tag))
    .map((tag) => `<a class="newsletter-tag" href="?tag=${encodeURIComponent(tag)}#${encodeURIComponent(edition.key)}">${escapeHtml(labels[tag] ?? tag)}</a>`)
    .join(' ');

  article.innerHTML = `
    <p class="edition-date"><time datetime="${escapeHtml(edition.date)}">${escapeHtml(formatDate(edition))}</time></p>
    <h2 class="edition-headline">${inline(edition.headline ?? '')}</h2>
    ${tags ? `<p class="edition-tags" aria-label="Topics">${tags}</p>` : ''}
    ${edition.intro ? `<div class="edition-intro">${block(edition.intro)}</div>` : ''}
    ${sections}
    ${edition.signoff ? `<div class="edition-signoff">${block(edition.signoff)}</div>` : ''}
    <p class="edition-permalink"><a href="#${encodeURIComponent(edition.key)}">Permalink</a> <span aria-hidden="true">·</span> <a href="${SOURCE}">Source code</a></p>`;
  document.title = `${edition.headline} — DiscordPHP Newsletter`;
}

function renderArchive(list, editions, current, labels) {
  list.innerHTML = editions
    .map((e) => {
      const here = e.key === current.key ? ' aria-current="page"' : '';
      const tags = (e.tags ?? []).map((tag) => `<span class="newsletter-tag">${escapeHtml(labels[tag] ?? tag)}</span>`).join(' ');
      return `<li><a href="#${encodeURIComponent(e.key)}"${here}>${escapeHtml(e.headline ?? e.key)}</a> <span>— ${escapeHtml(e.date)}</span>${tags ? `<div class="archive-tags">${tags}</div>` : ''}</li>`;
    })
    .join('');
}

function renderTagFilters(container, tags, selected, onSelect) {
  const buttons = [{ id: '', label: 'All topics' }, ...tags];
  container.innerHTML = buttons.map((tag) => {
    const active = tag.id === selected;
    return `<button type="button" class="newsletter-tag-filter${active ? ' is-active' : ''}" data-tag="${escapeHtml(tag.id)}" aria-pressed="${active}">${escapeHtml(tag.label)}</button>`;
  }).join('');
  container.querySelectorAll('button[data-tag]').forEach((button) => {
    button.addEventListener('click', () => onSelect(button.dataset.tag));
  });
}

async function showNewsletter() {
  const root = document.querySelector('[data-newsletter]');
  if (!root) {
    return;
  }
  const article = root.querySelector('.edition');
  const archive = root.querySelector('[data-archive]');
  const filters = root.querySelector('[data-newsletter-tags]');

  let editions = [];
  let labels = {};
  try {
    const [response, catalogResponse] = await Promise.all([
      fetch(DATA, { cache: 'no-cache' }),
      fetch(ECOSYSTEM_DATA, { cache: 'no-cache' }),
    ]);
    if (response.ok) editions = ((await response.json()).editions ?? []).filter((e) => e && e.key && e.date);
    if (catalogResponse.ok) {
      const tags = (await catalogResponse.json()).newsletterTags ?? [];
      labels = Object.fromEntries(tags.map((tag) => [tag.id, tag.label]));
    }
  } catch (error) {
    console.error('Could not load the newsletter', error);
  }
  editions.sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : String(b.key).localeCompare(String(a.key))));

  const availableTags = Object.entries(labels).map(([id, label]) => ({ id, label }));
  const getSelectedTag = () => {
    const tag = new URLSearchParams(window.location.search).get('tag') || '';
    return Object.hasOwn(labels, tag) ? tag : '';
  };
  const show = (scroll = false) => {
    const selected = getSelectedTag();
    const visible = selected ? editions.filter((edition) => (edition.tags ?? []).includes(selected)) : editions;
    if (filters) renderTagFilters(filters, availableTags, selected, (tag) => {
      const url = new URL(window.location.href);
      tag ? url.searchParams.set('tag', tag) : url.searchParams.delete('tag');
      history.pushState(null, '', url);
      show();
    });

    if (visible.length === 0) {
      article.innerHTML = `<p class="note">${editions.length ? 'No editions match this topic yet.' : 'No editions have been published yet. Check back soon.'}</p>`;
      archive.replaceChildren();
      root.querySelector('.edition-archive').hidden = editions.length === 0;
      return;
    }

    root.querySelector('.edition-archive').hidden = false;
    const wanted = decodeURIComponent(window.location.hash.slice(1));
    const edition = visible.find((e) => e.key === wanted) ?? visible[0];
    renderEdition(article, edition, labels);
    renderArchive(archive, visible, edition, labels);
    if (scroll) article.scrollIntoView({ block: 'start' });
  };
  window.addEventListener('hashchange', () => show(true));
  window.addEventListener('popstate', () => show());
  show();
}

document.addEventListener('DOMContentLoaded', showNewsletter);
