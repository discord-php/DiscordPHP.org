// DiscordPHP.org — theme, navigation and diagrams. No build step, no framework.

const MERMAID = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';

const storage = {
  get(key) {
    try {
      return window.localStorage.getItem(key);
    } catch {
      return null;
    }
  },
  set(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      // Private windows and blocked storage: the choice just is not remembered.
    }
  },
};

function currentTheme() {
  const chosen = document.documentElement.dataset.theme;
  if (chosen === 'light' || chosen === 'dark') {
    return chosen;
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyStoredTheme() {
  const stored = storage.get('theme');
  if (stored === 'light' || stored === 'dark') {
    document.documentElement.dataset.theme = stored;
  }
}

function wireThemeToggle() {
  const button = document.querySelector('.theme-toggle');
  if (!button) {
    return;
  }
  const label = () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    button.textContent = next === 'dark' ? '☾' : '☀';
    button.setAttribute('aria-label', `Switch to ${next} theme`);
    button.title = `Switch to ${next} theme`;
  };
  label();
  button.addEventListener('click', () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    storage.set('theme', next);
    label();
    rerenderDiagrams();
  });
}

function wireNavToggle() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (!toggle || !nav) {
    return;
  }
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
  });
}

// ---- diagrams ----

let mermaidModule = null;
let renderCount = 0;

async function mermaid() {
  if (!mermaidModule) {
    mermaidModule = (await import(MERMAID)).default;
  }
  mermaidModule.initialize({
    startOnLoad: false,
    theme: currentTheme() === 'dark' ? 'dark' : 'default',
    securityLevel: 'strict',
    // Full size, scrolling sideways inside their box: shrunk to the page, the big ones are unreadable.
    class: { useMaxWidth: false },
    flowchart: { useMaxWidth: false },
    // Long messages wrap instead of pushing the participants apart.
    sequence: { useMaxWidth: false, wrap: true, width: 170 },
  });
  return mermaidModule;
}

// One diagram at a time: Mermaid is re-initialised for the theme before each render.
let queue = Promise.resolve();

/**
 * Shows a drawn diagram at its own size, scrolling sideways inside its box, since a big class
 * diagram shrunk to the page is unreadable. One wider than its box gets a button to fit it.
 */
function naturalSize(pre) {
  const svg = pre.querySelector('svg');
  const box = svg?.viewBox?.baseVal;
  if (!svg || !box || !box.width) {
    return;
  }
  svg.setAttribute('width', String(Math.ceil(box.width)));
  svg.setAttribute('height', String(Math.ceil(box.height)));
  svg.style.maxWidth = 'none';

  const frame = pre.closest('.diagram');
  if (!frame || frame.querySelector('.diagram-tools') || box.width <= pre.clientWidth) {
    return;
  }
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = 'Fit to width';
  button.addEventListener('click', () => {
    const fit = frame.classList.toggle('fit');
    button.textContent = fit ? 'Actual size' : 'Fit to width';
  });
  const tools = document.createElement('div');
  tools.className = 'diagram-tools';
  tools.append(button);
  frame.prepend(tools);
}

async function draw(pre) {
  if (!pre.dataset.source) {
    pre.dataset.source = pre.textContent;
  }
  const engine = await mermaid();
  try {
    const { svg } = await engine.render(`diagram-${++renderCount}`, pre.dataset.source);
    pre.innerHTML = svg;
    pre.dataset.rendered = 'true';
    naturalSize(pre);
  } catch (error) {
    pre.textContent = pre.dataset.source;
    if (!pre.previousElementSibling?.classList.contains('diagram-error')) {
      pre.insertAdjacentHTML('beforebegin', '<p class="note diagram-error">This diagram could not be drawn, so its source is shown instead.</p>');
    }
    console.error(error);
  }
}

/** Renders one `pre.mermaid` in place, keeping its source for a theme change. */
export function renderDiagram(pre) {
  queue = queue.then(() => draw(pre));
  return queue;
}

function rerenderDiagrams() {
  for (const pre of document.querySelectorAll('pre.mermaid[data-rendered="true"]')) {
    renderDiagram(pre);
  }
}

function renderVisibleDiagrams() {
  // Lazy diagrams wait until their group is opened: the routes page has two dozen.
  for (const pre of document.querySelectorAll('pre.mermaid:not([data-lazy])')) {
    renderDiagram(pre);
  }
}

applyStoredTheme();
document.addEventListener('DOMContentLoaded', () => {
  wireThemeToggle();
  wireNavToggle();
  renderVisibleDiagrams();
});
