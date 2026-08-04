import { __ } from '@wordpress/i18n';
import { CheckboxControl, TextControl, TextareaControl, SelectControl } from '@wordpress/components';

/**
 * `email_settings` panel for a `lead_capture` node: whether to persist the
 * submission, and the admin/user notification emails - both templated
 * against the node's own `form_fields`, expanded server-side in
 * RYV_API::render_template().
 */
export default function EmailSettingsEditor({ emailSettings, formFields, onChange }) {
  const settings = emailSettings || {};
  const update = (patch) => onChange({ ...settings, ...patch });

  const emailFieldOptions = [
    { label: __('— Select a field —', 'ryv-eligibility-tool'), value: '' },
    ...(formFields || [])
      .filter((field) => field.type === 'email' && field.name)
      .map((field) => ({ label: field.label || field.name, value: field.name })),
  ];

  return (
    <div className="ryv-builder-email-settings">
      <p className="ryv-builder-field-help">
        {__(
          'Use {field_name} in a subject or body below to inject a submitted value (e.g. {email}), or {all_fields} to dump every submitted field as a "Label: Value" summary.',
          'ryv-eligibility-tool'
        )}
      </p>

      <CheckboxControl
        label={__('Save submission to the database', 'ryv-eligibility-tool')}
        checked={!!settings.save_to_db}
        onChange={(save_to_db) => update({ save_to_db })}
      />

      <h3>{__('Admin Notification', 'ryv-eligibility-tool')}</h3>

      <CheckboxControl
        label={__('Email the admin on submission', 'ryv-eligibility-tool')}
        checked={!!settings.enable_admin_email}
        onChange={(enable_admin_email) => update({ enable_admin_email })}
      />

      {settings.enable_admin_email && (
        <>
          <TextControl
            label={__('Send to', 'ryv-eligibility-tool')}
            value={settings.admin_email_to || ''}
            onChange={(admin_email_to) => update({ admin_email_to })}
            help={__('Comma-separated for multiple recipients.', 'ryv-eligibility-tool')}
          />
          <TextControl
            label={__('Subject', 'ryv-eligibility-tool')}
            value={settings.admin_email_subject || ''}
            onChange={(admin_email_subject) => update({ admin_email_subject })}
          />
          <TextareaControl
            label={__('Body', 'ryv-eligibility-tool')}
            value={settings.admin_email_body || ''}
            onChange={(admin_email_body) => update({ admin_email_body })}
          />
        </>
      )}

      <h3>{__('User Notification', 'ryv-eligibility-tool')}</h3>

      <CheckboxControl
        label={__('Email the user on submission', 'ryv-eligibility-tool')}
        checked={!!settings.enable_user_email}
        onChange={(enable_user_email) => update({ enable_user_email })}
      />

      {settings.enable_user_email && (
        <>
          <SelectControl
            label={__('User email field', 'ryv-eligibility-tool')}
            value={settings.user_email_field || ''}
            options={emailFieldOptions}
            onChange={(user_email_field) => update({ user_email_field })}
            help={__(
              'Which "Email" type field (from the Form Fields tab) holds the address to send this to.',
              'ryv-eligibility-tool'
            )}
          />
          <TextControl
            label={__('Subject', 'ryv-eligibility-tool')}
            value={settings.user_email_subject || ''}
            onChange={(user_email_subject) => update({ user_email_subject })}
          />
          <TextareaControl
            label={__('Body', 'ryv-eligibility-tool')}
            value={settings.user_email_body || ''}
            onChange={(user_email_body) => update({ user_email_body })}
          />
        </>
      )}
    </div>
  );
}