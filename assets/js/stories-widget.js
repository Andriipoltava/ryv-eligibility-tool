/**
 * RYV Stories widget: swaps the featured video's static thumbnail for a
 * muted, autoplaying YouTube embed once it scrolls into view.
 *
 * Deliberately not an eager `<iframe src="...">` on page load - that would
 * autoplay every instance of the widget on the page (and every embed's own
 * network/JS weight) regardless of whether a visitor ever sees it. The
 * `<img>` thumbnail (widgets/class-ryv-stories-widget.php's render_featured())
 * is what actually paints on load; this script only ever runs once per
 * `.ryv-stories-widget-video[data-youtube-id]` element, the first time it
 * crosses the viewport.
 *
 * Clicking anywhere on the panel (the play button, or the panel itself)
 * still opens the real YouTube video in a new tab - see this widget's own
 * docblock for why that's the entire "watch" interaction, not an in-page
 * player. The muted embed here is ambient background motion only, matching
 * the "Featured Video Story" preview treatment in the design.
 */
( function () {
	'use strict';

	function loadEmbed( panel ) {
		if ( panel.dataset.ryvLoaded ) {
			return;
		}
		panel.dataset.ryvLoaded = '1';

		var videoId = panel.dataset.youtubeId;
		if ( ! videoId ) {
			return;
		}

		var iframe = document.createElement( 'iframe' );
		iframe.className = 'ryv-stories-widget-video-frame';
		iframe.src =
			'https://www.youtube-nocookie.com/embed/' +
			encodeURIComponent( videoId ) +
			'?autoplay=1&mute=1&loop=1&playlist=' +
			encodeURIComponent( videoId ) +
			'&controls=0&modestbranding=1&rel=0&playsinline=1';
		iframe.setAttribute( 'title', 'Story video preview' );
		iframe.setAttribute( 'frameborder', '0' );
		iframe.setAttribute( 'allow', 'autoplay; encrypted-media; picture-in-picture' );
		iframe.setAttribute( 'aria-hidden', 'true' );
		iframe.setAttribute( 'tabindex', '-1' );

		panel.appendChild( iframe );
		panel.classList.add( 'is-playing' );
	}

	function init() {
		var panels = document.querySelectorAll( '.ryv-stories-widget-video[data-autoplay="1"][data-youtube-id]' );
		if ( ! panels.length ) {
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			// No IntersectionObserver support: fall back to loading every
			// panel immediately rather than never autoplaying at all.
			panels.forEach( loadEmbed );
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						loadEmbed( entry.target );
						observer.unobserve( entry.target );
					}
				} );
			},
			{ threshold: 0.5 }
		);

		panels.forEach( function ( panel ) {
			observer.observe( panel );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
