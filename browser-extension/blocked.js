// Everything read from the address is written with textContent, never as markup.
const marker = location.search.indexOf('&u=');
const reason = /[?&]reason=(blocked|focus)(?:&|$)/.exec(location.search)?.[1];

let host = '';
if (marker !== -1) {
  try {
    const original = new URL(location.search.slice(marker + 3));
    if (original.protocol === 'http:' || original.protocol === 'https:') host = original.hostname;
  } catch {
    // Not a web address: show nothing about it.
  }
}

document.getElementById('why').textContent =
  reason === 'focus'
    ? 'Your teacher has limited which sites you can visit during this activity.'
    : 'This site is blocked on school computers.';
document.getElementById('site').textContent = host;
document.getElementById('back').addEventListener('click', () => history.back());
