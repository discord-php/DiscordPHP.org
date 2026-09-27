// The home page's numbers, read from the generated route map.

async function loadStats() {
  const root = document.getElementById('stats');
  if (!root) {
    return;
  }
  try {
    const response = await fetch('data/routes.json', { cache: 'no-cache' });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const data = await response.json();
    const values = {
      sent: data.totals.sent,
      operations: data.totals.operations,
      events: data.totals.events,
      groups: data.totals.groups,
      release: data.release ?? data.discordphp.slice(0, 9),
    };
    for (const [key, value] of Object.entries(values)) {
      for (const node of root.querySelectorAll(`[data-stat="${key}"]`)) {
        node.textContent = String(value);
      }
    }
  } catch (error) {
    root.hidden = true;
    console.error('Could not load the route map', error);
  }
}

document.addEventListener('DOMContentLoaded', loadStats);
