import { ComboboxControl } from '@wordpress/components';

const EMPTY = '__none__';

/**
 * Searchable replacement for a <select> of node ids (a real form has 400+
 * nodes, so scrolling a native list is impractical). Same value contract as
 * SelectControl: the empty-string option ("— End —"/"— None —") means "no
 * node", and `onChange` always receives a string ('' for none).
 */
export default function NodeSelect({ options, value, onChange, label, hideLabelFromVision, className }) {
  // ComboboxControl can't hold an option whose value is '' (falsy = "nothing
  // selected"), so the empty option travels under a sentinel.
  const mapped = options.map((o) => ({ ...o, value: o.value === '' ? EMPTY : o.value }));

  return (
    <ComboboxControl
      className={className}
      label={label}
      hideLabelFromVision={hideLabelFromVision}
      value={value === '' || value == null ? EMPTY : value}
      options={mapped}
      allowReset={false}
      onChange={(next) => onChange(!next || next === EMPTY ? '' : next)}
    />
  );
}
