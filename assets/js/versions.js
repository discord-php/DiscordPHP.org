// Fills in each `data-version` badge with the latest release, looked up when the site was built.

async function showVersions() {
  const badges = document.querySelectorAll('[data-version]');
  if (badges.length === 0) {
    return;
  }
  try {
    const response = await fetch('data/versions.json', { cache: 'no-cache' });
    if (!response.ok) {
      return;
    }
    const { packages } = await response.json();
    for (const badge of badges) {
      const found = packages[badge.dataset.version];
      if (found) {
        badge.textContent = found.version;
        badge.title = found.time ? `Released ${new Date(found.time).toLocaleDateString()}` : '';
        badge.hidden = false;
      }
    }
  } catch (error) {
    // No versions: the badges stay hidden and nothing else depends on them.
    console.error('Could not load package versions', error);
  }
}

document.addEventListener('DOMContentLoaded', showVersions);
