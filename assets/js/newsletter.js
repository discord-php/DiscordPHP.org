// The newsletter page: shows one edition from data/newsletter.json (the newest, or the one named in
// the URL's #fragment) and lists the rest as an archive.
//
// Editions are written by the DiscordPHP-Newsletter bot in Discord-flavoured markdown. Everything is
// escaped before the small subset of formatting below is applied, so an edition can never inject HTML.

const DATA = 'data/newsletter.json';

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

function renderEdition(article, edition) {
  const sections = (edition.sections ?? [])
    .map((s) => `<section>${s.title ? `<h3>${inline(s.title)}</h3>` : ''}${block(s.body ?? '')}</section>`)
    .join('');

  article.innerHTML = `
    <p class="edition-date"><time datetime="${escapeHtml(edition.date)}">${escapeHtml(formatDate(edition))}</time></p>
    <h2 class="edition-headline">${inline(edition.headline ?? '')}</h2>
    ${edition.intro ? `<div class="edition-intro">${block(edition.intro)}</div>` : ''}
    ${sections}
    ${edition.signoff ? `<div class="edition-signoff">${block(edition.signoff)}</div>` : ''}
    <p class="edition-permalink"><a href="#${encodeURIComponent(edition.key)}">Permalink</a> <span aria-hidden="true">·</span> <a href="${SOURCE}">Source code</a></p>`;
  document.title = `${edition.headline} — DiscordPHP Newsletter`;
}

function renderArchive(list, editions, current) {
  list.innerHTML = editions
    .map((e) => {
      const here = e.key === current.key ? ' aria-current="page"' : '';
      return `<li><a href="#${encodeURIComponent(e.key)}"${here}>${escapeHtml(e.headline ?? e.key)}</a> <span>— ${escapeHtml(e.date)}</span></li>`;
    })
    .join('');
}

async function showNewsletter() {
  const root = document.querySelector('[data-newsletter]');
  if (!root) {
    return;
  }
  const article = root.querySelector('.edition');
  const archive = root.querySelector('[data-archive]');

  let editions = [];
  try {
    const response = await fetch(DATA, { cache: 'no-cache' });
    if (response.ok) {
      editions = ((await response.json()).editions ?? []).filter((e) => e && e.key && e.date);
    }
  } catch (error) {
    console.error('Could not load the newsletter', error);
  }
  editions.sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : String(b.key).localeCompare(String(a.key))));

  if (editions.length === 0) {
    article.innerHTML = '<p class="note">No editions have been published yet. Check back soon.</p>';
    root.querySelector('.edition-archive').hidden = true;
    return;
  }

  const show = () => {
    const wanted = decodeURIComponent(window.location.hash.slice(1));
    const edition = editions.find((e) => e.key === wanted) ?? editions[0];
    renderEdition(article, edition);
    renderArchive(archive, editions, edition);
  };
  window.addEventListener('hashchange', () => {
    show();
    article.scrollIntoView({ block: 'start' });
  });
  show();
}

document.addEventListener('DOMContentLoaded', showNewsletter);
