/**
 * RYV Share Links widget: "copy link" (Clipboard API, with a textarea
 * fallback for non-secure contexts) and "print". Delegated from `document`
 * so widgets Elementor's editor re-renders keep working.
 */
(() => {
	const flash = (button, text) => {
		const status = button.closest('.ryv-share-links')?.querySelector('.ryv-share-links__status');
		if (!status) {
			return;
		}
		status.textContent = text;
		status.classList.add('is-visible');
		clearTimeout(status._timer);
		status._timer = setTimeout(() => status.classList.remove('is-visible'), 2000);
	};

	const copy = async (text) => {
		if (navigator.clipboard && window.isSecureContext) {
			try {
				await navigator.clipboard.writeText(text);
				return;
			} catch (error) {
				// Denied (e.g. the document isn't focused) - fall through to
				// the legacy path below rather than silently doing nothing.
			}
		}
		const area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.append(area);
		area.select();
		document.execCommand('copy');
		area.remove();
	};

	document.addEventListener('click', async (event) => {
		const copyButton = event.target.closest?.('[data-ryv-copy]');
		if (copyButton) {
			try {
				await copy(copyButton.dataset.ryvCopy || location.href);
				flash(copyButton, copyButton.dataset.copied || '');
			} catch (error) {
				// Clipboard blocked - nothing sensible to show.
			}
			return;
		}

		if (event.target.closest?.('[data-ryv-print]')) {
			window.print();
		}
	});
})();
