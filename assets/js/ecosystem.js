// Shared renderer for the project catalog and release/compatibility view.

const GROUPS = {
  core: ['DiscordPHP and its parts', 'The core packages used to build Discord bots and applications.'],
  bridge: ['Chat bridge and connectors', 'Connect Discord, Twitch, Telegram and YouTube with the same bridge core.'],
  integrations: ['Integrations', 'Projects maintained independently that build on DiscordPHP.'],
  experimental: ['Experimental', 'These projects are in development and are not ready for production use.'],
  tools: ['Tools and bots', 'Tools for maintainers and bots that help run Discord communities.'],
  bots: ['Bots', 'Ready-to-run Discord bots and services.'],
  valgorithms: ['Other Valgorithms projects', 'Libraries maintained alongside the DiscordPHP ecosystem.'],
  websites: ['Websites', 'Project and collaborator websites.'],
  archived: ['Archived', 'Kept here for reference; these projects are no longer maintained.'],
};

function node(tag, className, text) {
  const item = document.createElement(tag);
  if (className) item.className = className;
  if (text != null) item.textContent = text;
  return item;
}

function link(label, url, className = '') {
  if (!/^https:\/\//.test(String(url || ''))) return null;
  const item = node('a', className, label);
  item.href = url;
  item.rel = 'noopener';
  return item;
}

function dateLabel(value) {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
}

function projectActions(project) {
  const actions = Array.isArray(project.nextSteps) ? [...project.nextSteps] : [];
  if (project.repository && !actions.some((action) => action.type === 'source')) {
    actions.push({ label: 'Source', url: `https://github.com/${project.repository}`, type: 'source' });
  }
  if (project.repository && !actions.some((action) => action.type === 'issues')) {
    actions.push({ label: 'Report an issue', url: `https://github.com/${project.repository}/issues`, type: 'issues' });
  }
  return actions;
}

function renderProjectCard(project, releases) {
  const article = node('article', 'card project-entry');
  const title = node('h3', 'project-title', project.name);
  if (project.status && project.status !== 'stable') {
    title.append(node('span', `tag ${project.status === 'experimental' || project.status === 'archived' ? 'warn' : ''}`, project.status));
  }
  article.append(title);
  if (project.package) article.append(node('p', 'package', project.package));
  article.append(node('p', 'project-description', project.description || ''));

  const release = releases[project.id]?.release;
  if (release?.version) {
    article.append(node('p', 'project-release', `${release.version}${release.time ? ` · released ${dateLabel(release.time)}` : ''}`));
  }

  const actions = node('div', 'links project-actions');
  for (const next of projectActions(project)) {
    const action = link(next.label, next.url, next.type === 'install' ? 'action-primary' : '');
    if (action) actions.append(action);
  }
  if (actions.childElementCount) article.append(actions);
  return article;
}

function renderProjectCatalog(root, catalog, releases) {
  const audience = root.dataset.projectCatalog;
  const projects = catalog.projects.filter((project) => Array.isArray(project.audiences) && project.audiences.includes(audience));
  if (!projects.length) {
    root.replaceChildren(node('p', 'note', 'No projects are listed here yet.'));
    return;
  }

  const groups = new Map();
  for (const project of projects) {
    const group = audience === 'valgorithms' ? (project.kind === 'integration' ? 'library' : project.kind) : (project.group || 'tools');
    if (!groups.has(group)) groups.set(group, []);
    groups.get(group).push(project);
  }

  const groupOrder = audience === 'valgorithms'
    ? ['library', 'tool', 'website', 'bot']
    : ['core', 'bridge', 'integrations', 'experimental', 'tools', 'bots', 'archived'];
  const fragment = document.createDocumentFragment();
  for (const key of groupOrder) {
    const entries = groups.get(key);
    if (!entries?.length) continue;
    const info = GROUPS[key] || [key, ''];
    const section = node('section', 'catalog-group');
    section.append(node('h2', '', audience === 'valgorithms' ? ({ library: 'Libraries', tool: 'Tools', website: 'Websites', bot: 'Bots' }[key] || key) : info[0]));
    if (info[1] && audience !== 'valgorithms') section.append(node('p', 'section-intro', info[1]));
    const grid = node('div', 'grid project-grid');
    for (const project of entries) grid.append(renderProjectCard(project, releases));
    section.append(grid);
    fragment.append(section);
  }
  root.replaceChildren(fragment);
}

function renderReleaseView(root, catalog, releases) {
  const projects = catalog.projects.filter((project) => Array.isArray(project.audiences) && project.audiences.length);
  const table = node('table', 'release-table');
  const head = node('thead');
  const header = node('tr');
  for (const label of ['Project', 'Latest release', 'PHP', 'DiscordPHP', 'Extensions', 'Next step']) header.append(node('th', '', label));
  head.append(header);
  const body = node('tbody');

  for (const project of projects) {
    const entry = releases[project.id] || {};
    const release = entry.release;
    const compatibility = entry.compatibility || {};
    const row = node('tr');
    const nameCell = node('td', 'release-project');
    nameCell.append(node('strong', '', project.name));
    nameCell.append(node('span', 'release-status', project.status || project.kind));
    if (project.package) nameCell.append(node('code', 'package', project.package));
    row.append(nameCell);

    const releaseCell = node('td');
    if (release?.version) {
      const releaseLink = link(release.version, release.url);
      if (releaseLink) releaseCell.append(releaseLink);
      else releaseCell.textContent = release.version;
      if (release.time) releaseCell.append(node('small', 'release-date', dateLabel(release.time)));
    } else {
      releaseCell.textContent = 'No stable release found';
    }
    row.append(releaseCell);

    const isPhpProject = Boolean(project.package) || ['library', 'integration'].includes(project.kind);
    const hasComposerMetadata = compatibility.available === true;
    const noMetadata = 'No Composer metadata';
    row.append(node('td', '', isPhpProject ? (hasComposerMetadata ? compatibility.php || 'Not declared' : noMetadata) : 'Not applicable'));
    row.append(node('td', '', project.id === 'discordphp' ? 'Core package' : (isPhpProject ? (hasComposerMetadata ? compatibility.discordphp || 'Not declared' : noMetadata) : 'Not applicable')));
    const extensions = Object.entries(compatibility.extensions || {})
      .map(([name, constraint]) => constraint ? `${name} ${constraint}` : name);
    row.append(node('td', '', !isPhpProject ? 'Not applicable' : (hasComposerMetadata ? (extensions.length ? extensions.join(', ') : 'None declared') : noMetadata)));

    const actionCell = node('td', 'release-next-step');
    const next = projectActions(project)[0];
    if (next) {
      const action = link(next.label, next.url);
      if (action) actionCell.append(action);
    }
    row.append(actionCell);
    body.append(row);
  }

  table.append(head, body);
  const scroller = node('div', 'table-scroll');
  scroller.append(table);
  root.replaceChildren(scroller);
}

async function loadEcosystem() {
  const catalogViews = document.querySelectorAll('[data-project-catalog], [data-release-view]');
  if (!catalogViews.length) return;
  const status = document.querySelector('[data-ecosystem-status]');
  try {
    const [catalogResponse, releasesResponse] = await Promise.all([
      fetch('data/ecosystem.json', { cache: 'no-cache' }),
      fetch('data/releases.json', { cache: 'no-cache' }),
    ]);
    if (!catalogResponse.ok) throw new Error(`Catalog returned ${catalogResponse.status}`);
    const catalog = await catalogResponse.json();
    const releaseData = releasesResponse.ok ? await releasesResponse.json() : {};
    const releases = releaseData.projects || {};
    for (const root of document.querySelectorAll('[data-project-catalog]')) renderProjectCatalog(root, catalog, releases);
    for (const root of document.querySelectorAll('[data-release-view]')) renderReleaseView(root, catalog, releases);
    if (status) status.textContent = `Release information generated ${dateLabel(releaseData.generated) || 'on the latest site build'}. Compatibility is read from each published Composer release.`;
  } catch (error) {
    for (const root of catalogViews) root.replaceChildren(node('p', 'note', 'The ecosystem catalog could not be loaded. Please use the project links on the Libraries page or try again later.'));
    if (status) status.textContent = 'The release catalog is temporarily unavailable.';
    console.error('Could not load the ecosystem catalog', error);
  }
}

document.addEventListener('DOMContentLoaded', loadEcosystem);
