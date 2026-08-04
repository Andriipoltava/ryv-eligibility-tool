import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * `[ryv_form id="X"]` reference + copy button for a single form, shown in
 * the builder header (App.js) so embedding the form outside Elementor - a
 * shortcode block, a theme template, wherever `do_shortcode()` reaches - is
 * discoverable right where the admin is already looking at this form's id.
 * See RYV_Shortcode (includes/class-ryv-shortcode.php) for what the tag
 * actually does.
 */
export default function ShortcodeSnippet({ postId }) {
  const [copied, setCopied] = useState(false);
  const shortcode = `[ryv_form id="${postId}"]`;

  const handleCopy = () => {
    navigator.clipboard.writeText(shortcode).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    });
  };

  return (
    <div className="ryv-builder-shortcode">
      <span className="ryv-builder-shortcode-label">{__('Shortcode:', 'ryv-eligibility-tool')}</span>
      <code>{shortcode}</code>
      <Button variant="secondary" isSmall onClick={handleCopy}>
        {copied ? __('Copied!', 'ryv-eligibility-tool') : __('Copy', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
