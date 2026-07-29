import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';

/**
 * A destructive action that requires a second click to fire, without a
 * blocking native confirm() dialog.
 */
export default function ConfirmButton({ label, confirmLabel, onConfirm, ...props }) {
  const [confirming, setConfirming] = useState(false);

  if (confirming) {
    return (
      <Button
        {...props}
        isDestructive
        onClick={() => {
          setConfirming(false);
          onConfirm();
        }}
        onBlur={() => setConfirming(false)}
      >
        {confirmLabel}
      </Button>
    );
  }

  return (
    <Button {...props} onClick={() => setConfirming(true)}>
      {label}
    </Button>
  );
}
