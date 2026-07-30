const DEFAULT_BASE = '/wp-json/ryv/v1';

function settings() {
  return window.RYVSettings || {};
}

async function request(path) {
  const base = (settings().restUrl || DEFAULT_BASE).replace(/\/$/, '');
  const headers = { Accept: 'application/json' };

  if (settings().nonce) {
    headers['X-WP-Nonce'] = settings().nonce;
  }

  const response = await fetch(`${base}${path}`, {
    headers,
    credentials: 'same-origin',
  });

  if (!response.ok) {
    let message = `Request failed (${response.status})`;
    try {
      const body = await response.json();
      if (body && body.message) {
        message = body.message;
      }
    } catch (e) {
      // Non-JSON error body - keep the generic message.
    }
    throw new Error(message);
  }

  return response.json();
}

/** Fetches the whole flat graph ({ form_id, title, start_node, nodes }) in one call. */
export function fetchForm(formId) {
  return request(`/form/${encodeURIComponent(formId)}`);
}