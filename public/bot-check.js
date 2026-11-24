/*
 * FormHaven bot check. Add it once to a page:
 *   <script src="https://YOUR-FORMHAVEN-HOST/bot-check.js" async></script>
 * (the dashboard and get_form_code give the line with this install's own address).
 *
 * For every form on the page that posts to FormHaven it fetches a small puzzle and
 * solves it in the background (proof of work), then puts the answer in a hidden
 * _botcheck field. Visitors see nothing and click nothing. No cookies, no tracking,
 * and nothing is sent anywhere but the form's own FormHaven address.
 */
(() => {
  const FIELD = '_botcheck';
  // The install that served this file is the one its forms post to. currentScript is null in a few
  // loaders; then the script tag that loaded bot-check.js says where it came from. Without either
  // it does nothing, rather than guess another server.
  const script = document.currentScript || document.querySelector('script[src$="/bot-check.js"]');
  if (!script || !script.src) return;
  const origin = new URL(script.src).origin;
  const encoder = new TextEncoder();
  const jobs = new WeakMap();
  const settled = new WeakSet();

  const hex = (buffer) => Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');

  // Tries numbers in batches of 500, so the browser hashes in parallel and stays responsive.
  async function solve(puzzle) {
    for (let start = 0; start <= puzzle.maxnumber; start += 500) {
      const batch = [];
      for (let n = start; n < start + 500 && n <= puzzle.maxnumber; n++) {
        batch.push(crypto.subtle.digest('SHA-256', encoder.encode(puzzle.salt + n)).then((h) => (hex(h) === puzzle.challenge ? n : -1)));
      }
      const found = (await Promise.all(batch)).find((n) => n >= 0);
      if (found !== undefined) {
        const { algorithm, challenge, salt, signature } = puzzle;
        return btoa(JSON.stringify({ algorithm, challenge, number: found, salt, signature }));
      }
    }
    return '';
  }

  function formId(form) {
    try {
      const action = new URL(form.getAttribute('action') || '', location.href);
      const match = action.origin === origin && action.pathname.match(/^\/f\/([A-Za-z0-9]+)\/?$/);
      return match ? match[1] : null;
    } catch {
      return null;
    }
  }

  // The hidden field stays out of the form until it is sent. Adding it to a form the
  // server rendered makes React, Vue and Svelte see markup they did not render when they
  // hydrate: Vue then deletes the field (every Nuxt post went to spam) and React throws
  // the page away and draws it again (Gatsby, 2026-11-20).
  const inputs = new WeakMap();

  function prepare(form) {
    const id = formId(form);
    if (!id) return;

    let input = inputs.get(form) || form.querySelector(`input[name="${FIELD}"]`);
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = FIELD;
    }
    inputs.set(form, input);
    input.value = '';
    settled.delete(form);

    jobs.set(form, fetch(`${origin}/f/${id}/challenge`, { credentials: 'omit' })
      .then((response) => (response.ok ? response.json() : Promise.reject(new Error('No puzzle'))))
      .then(solve)
      .then((answer) => { input.value = answer; })
      .catch(() => {})
      .finally(() => settled.add(form)));
  }

  // Runs before the site's own submit handlers. A form sent before its puzzle is solved
  // waits for it, then sends again as the visitor meant (same button, same handlers).
  window.addEventListener('submit', (event) => {
    const form = event.target;
    if (!jobs.has(form)) return;

    // Before the site's handler builds its FormData and before the browser reads the form.
    const input = inputs.get(form);
    if (!form.contains(input)) form.appendChild(input);

    if (input.value || settled.has(form)) {
      // A fresh puzzle for the next send, for sites that keep the visitor on the page.
      setTimeout(() => prepare(form), 0);
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    const submitter = event.submitter && event.submitter.form === form ? event.submitter : undefined;
    jobs.get(form).then(() => form.requestSubmit(submitter));
  }, true);

  function scan(root) {
    const forms = root.tagName === 'FORM' ? [root] : Array.from(root.querySelectorAll?.('form') || []);
    forms.forEach((form) => { if (!jobs.has(form)) prepare(form); });
  }

  // Forms rendered later (React, Vue, a modal) get their puzzle when they appear.
  function start() {
    scan(document);
    new MutationObserver((changes) => changes.forEach((change) => change.addedNodes.forEach((node) => {
      if (node.nodeType === 1) scan(node);
    }))).observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
