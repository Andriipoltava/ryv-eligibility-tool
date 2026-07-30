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

export function fetchForm(formId) {
  return request(`/form/${encodeURIComponent(formId)}`);
}

/**
 * Fire-and-forget progress log (see RYV_Submission_Log on the PHP side) -
 * failures are swallowed rather than surfaced, since a dropped log write
 * should never interrupt someone filling out the actual questionnaire.
 */
export function logSubmission(formId, payload) {
  const base = (settings().restUrl || DEFAULT_BASE).replace(/\/$/, '');
  const headers = { 'Content-Type': 'application/json' };

  if (settings().nonce) {
    headers['X-WP-Nonce'] = settings().nonce;
  }

  return fetch(`${base}/form/${encodeURIComponent(formId)}/log`, {
    method: 'POST',
    headers,
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  }).catch(() => {});
}
