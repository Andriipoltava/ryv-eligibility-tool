const settings = () => window.RYVBuilderSettings || {};

async function request(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': settings().nonce || '',
      ...(options.headers || {}),
    },
  });

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error((body && body.message) || `Request failed (${response.status})`);
  }

  return body;
}

export function fetchForm(postId) {
  return request(`${settings().restUrl}/form/${postId}`);
}

export function saveForm(postId, schema) {
  return request(`${settings().restUrl}/form/${postId}`, {
    method: 'POST',
    body: JSON.stringify({ schema }),
  });
}

/** Post creation rides on WordPress's own already-REST-enabled CPT - no need for a bespoke endpoint. */
export function createForm(title) {
  return request(`${settings().wpRestUrl}wp/v2/ryv_forms`, {
    method: 'POST',
    body: JSON.stringify({ title, status: 'draft' }),
  });
}

/**
 * Same reasoning as createForm() - status is a plain post field, so this
 * rides on core's REST controller for the CPT rather than going through the
 * schema-only admin API. Every new form starts as 'draft' and the builder
 * (which fully replaces the normal post editor, Publish box included) is
 * otherwise the only place left to change that.
 */
export function updateFormStatus(postId, status) {
  return request(`${settings().wpRestUrl}wp/v2/ryv_forms/${postId}`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}

/**
 * Title/description are likewise plain post fields (post_title/post_excerpt,
 * the same ones the Elementor widget's show_title/show_description controls
 * read directly) - not part of the schema blob, so this rides on core's REST
 * controller too rather than the schema-only admin API. `fields` is e.g.
 * `{ title }`, `{ description }` (core's own field name is `excerpt`; the
 * caller uses `description` since that's the name used everywhere else in
 * this plugin - translate it here) or both together.
 */
export function updateFormMeta(postId, fields) {
  const { description, ...rest } = fields;
  const payload = { ...rest };
  if (description !== undefined) payload.excerpt = description;

  return request(`${settings().wpRestUrl}wp/v2/ryv_forms/${postId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}
